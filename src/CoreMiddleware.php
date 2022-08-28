<?php

declare(strict_types=1);

namespace PeibinLaravel\WebSocketServer;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request as IlluminateRequest;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use PeibinLaravel\Context\Context;
use PeibinLaravel\Server\Contracts\CoreMiddlewareInterface;
use PeibinLaravel\WebSocketServer\Exceptions\WebSocketHandeShakeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CoreMiddleware implements CoreMiddlewareInterface
{
    public const HANDLER_NAME = 'class';

    public function __construct(protected Container $container, protected string $serverName)
    {
    }

    public function dispatch(Request|IlluminateRequest $request): Request
    {
        /** @var RouteCollection|null $routers */
        $routers = $this->container->get(Request::class)->attributes->get($this->serverName);
        if (!$routers) {
            throw new NotFoundHttpException();
        }

        $route = $routers->match($request);
        $route->setContainer($this->container);

        $request->setRouteResolver(fn() => $route);
        $request->attributes->set($this->serverName, $routers);
        $request->attributes->set(Route::class, $route);
        $this->container->instance(Route::class, $route);

        return $request;
    }

    public function handle(Request $request, Closure $next)
    {
        /** @var Route $route */
        $route = $request->attributes->get(Route::class);
        $controller = $route->getControllerClass();
        if (!class_exists($controller)) {
            throw new WebSocketHandeShakeException('Router not exist.');
        }

        /** @var SymfonyResponse $response */
        $response = Context::get(SymfonyResponse::class);

        $security = $this->container->get(Security::class);

        $key = $request->headers->get(Security::SEC_WEBSOCKET_KEY);

        $response->setStatusCode(101);
        $response->headers->add($security->handshakeHeaders($key));
        if ($wsProtocol = $request->headers->get(Security::SEC_WEBSOCKET_PROTOCOL)) {
            $response->headers->set(Security::SEC_WEBSOCKET_PROTOCOL, $wsProtocol);
        }
        Context::set(SymfonyResponse::class, $response);

        $request->attributes->set(self::HANDLER_NAME, $controller);

        return $next($request);
    }
}
