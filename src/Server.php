<?php

declare(strict_types=1);

namespace PeibinLaravel\WebSocketServer;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request as IlluminateRequest;
use Illuminate\Routing\Router;
use PeibinLaravel\Context\Context;
use PeibinLaravel\Contracts\StdoutLoggerInterface;
use PeibinLaravel\Coordinator\Constants;
use PeibinLaravel\Coordinator\CoordinatorManager;
use PeibinLaravel\Coroutine\Coroutine;
use PeibinLaravel\Server\Actions\ConvertSwooleRequestToSymfonyRequest;
use PeibinLaravel\Server\Contracts\CoreMiddlewareInterface;
use PeibinLaravel\Server\Contracts\MiddlewareInitializerInterface;
use PeibinLaravel\Server\Contracts\OnCloseInterface;
use PeibinLaravel\Server\Contracts\OnHandShakeInterface;
use PeibinLaravel\Server\Contracts\OnMessageInterface;
use PeibinLaravel\Server\Contracts\OnOpenInterface;
use PeibinLaravel\Support\SafeCaller;
use PeibinLaravel\WebSocketServer\Collectors\FdCollector;
use PeibinLaravel\WebSocketServer\Context as WsContext;
use PeibinLaravel\WebSocketServer\Exceptions\WebSocketHandeShakeException;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Server as SwooleServer;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as WebSocketServer;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

class Server implements MiddlewareInitializerInterface, OnHandShakeInterface, OnCloseInterface, OnMessageInterface
{
    protected ?CoreMiddlewareInterface $coreMiddleware;

    protected string $serverName = 'websocket';

    protected mixed $server = null;

    public function __construct(
        protected Container $container,
        protected HttpDispatcher $httpDispatcher,
        protected ExceptionHandler $exceptionHandler,
        protected ResponseEmitter $responseEmitter,
        protected StdoutLoggerInterface $logger
    ) {
    }

    public function initCoreMiddleware(string $serverName): void
    {
        $this->serverName = $serverName;
        $this->coreMiddleware = new CoreMiddleware($this->container, $serverName);
    }

    public function getServer()
    {
        return $this->server ??= $this->container->get(SwooleServer::class);
    }

    public function onHandShake(Request $request, Response $response): void
    {
        try {
            CoordinatorManager::until(Constants::WORKER_START)->yield();

            $fd = $this->getFd($response);
            Context::set(WsContext::FD, $fd);
            $security = $this->container->get(Security::class);

            $this->initResponse();
            $symfonyRequest = $this->initRequest($request);

            $key = $symfonyRequest->headers->get('sec-websocket-key');
            if ($security->isInvalidSecurityKey($key)) {
                throw new WebSocketHandeShakeException('sec-websocket-key is invalid!');
            }

            $this->logger->debug(sprintf('WebSocket: fd[%d] start a handshake request.', $fd));

            /** @var SymfonyRequest|IlluminateRequest $symfonyRequest */
            $symfonyRequest = $this->coreMiddleware->dispatch($symfonyRequest);

            $route = $symfonyRequest->route();
            $router = $this->container->get(Router::class);
            $middlewares = $router->gatherRouteMiddleware($route);

            /** @var SymfonyResponse $symfonyResponse */
            $symfonyResponse = $this->httpDispatcher->dispatch($symfonyRequest, $middlewares, $this->coreMiddleware);

            $class = $symfonyRequest->attributes->get(CoreMiddleware::HANDLER_NAME);
            if (empty($class)) {
                $this->logger->warning('WebSocket hande shake failed, because the class does not exists.');
                return;
            }

            FdCollector::set($fd, $class);
            $server = $this->getServer();
            $this->deferOnOpen($request, $class, $server, $fd);
        } catch (Throwable $throwable) {
            $this->logger->error(
                "[{$throwable->getCode()}]{$throwable->getMessage()}[{$throwable->getFile()}:{$throwable->getLine()}]"
            );

            // Delegate the exception to exception handler.
            $symfonyResponse = $this->container->make(SafeCaller::class)->call(
                function () use ($throwable) {
                    $this->exceptionHandler->report($throwable);
                    $request = Context::get(SymfonyRequest::class);
                    return $this->exceptionHandler->render($request, $throwable);
                },
                static function () {
                    return (new SymfonyResponse())->setStatusCode(400);
                }
            );

            isset($fd) && FdCollector::del($fd);
            isset($fd) && WsContext::release($fd);
        } finally {
            // Send the Response to client.
            if (isset($symfonyResponse) && $symfonyResponse instanceof SymfonyResponse) {
                $this->responseEmitter->emit($symfonyResponse, $response, true);
            }
        }
    }

    public function onMessage(WebSocketServer $server, Frame $frame): void
    {
        $fd = $frame->fd;

        Context::set(WsContext::FD, $fd);
        $fdObj = FdCollector::get($fd);
        if (!$fdObj) {
            $this->logger->warning(sprintf('WebSocket: fd[%d] does not exist.', $fd));
            return;
        }

        $instance = $this->container->get($fdObj->class);
        if (!$instance instanceof OnMessageInterface) {
            $this->logger->warning(sprintf('%s is not instanceof %s', $instance, OnMessageInterface::class));
            return;
        }

        try {
            $instance->onMessage($server, $frame);
        } catch (Throwable $exception) {
            $this->logger->error((string)$exception);
        }
    }

    public function onClose(SwooleServer $server, int $fd, int $reactorId): void
    {
        $fdObj = FdCollector::get($fd);
        if (!$fdObj) {
            return;
        }

        $this->logger->debug(sprintf('WebSocket: fd[%d] closed.', $fd));

        Context::set(WsContext::FD, $fd);
        Coroutine::defer(function () use ($fd) {
            // Move those functions to defer, because onClose may throw exceptions.
            FdCollector::del($fd);
            WsContext::release($fd);
        });

        $instance = $this->container->get($fdObj->class);
        if ($instance instanceof OnCloseInterface) {
            try {
                $instance->onClose($server, $fd, $reactorId);
            } catch (Throwable $exception) {
                $this->logger->error((string)$exception);
            }
        }
    }

    protected function getFd(Response $response): int
    {
        return $this->container->get(FdGetter::class)->get($response);
    }

    protected function deferOnOpen($request, string $class, SwooleServer | WebSocketServer $server, int $fd): void
    {
        $instance = $this->container->get($class);
        Coroutine::defer(static function () use ($request, $instance, $server, $fd) {
            Context::set(WsContext::FD, $fd);
            if ($instance instanceof OnOpenInterface) {
                $instance->onOpen($server, $request);
            }
        });
    }

    protected function initRequest(mixed $request): SymfonyRequest
    {
        if ($request instanceof SymfonyRequest) {
            $symfonyRequest = $request;
        } else {
            $symfonyRequest = (new ConvertSwooleRequestToSymfonyRequest())($request);
        }

        Context::set(SymfonyRequest::class, $symfonyRequest);
        // WsContext::set(SymfonyRequest::class, $symfonyRequest);
        return $symfonyRequest;
    }

    protected function initResponse(): SymfonyResponse
    {
        Context::set(SymfonyResponse::class, $response = new SymfonyResponse());
        return $response;
    }
}
