<?php

declare(strict_types=1);

namespace PeibinLaravel\WebSocketServer;

use Illuminate\Contracts\Container\Container;
use Illuminate\Routing\Pipeline;
use PeibinLaravel\Context\Context;
use PeibinLaravel\Contracts\MiddlewareInterface;
use PeibinLaravel\Server\Contracts\DispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class HttpDispatcher implements DispatcherInterface
{
    public function __construct(protected Container $container)
    {
    }

    public function dispatch(...$params): mixed
    {
        /**
         * @param Request             $request
         * @param array               $middlewares
         * @param MiddlewareInterface $coreHandler
         */
        [$request, $middlewares, $coreHandler] = $params;

        $middlewares = $this->shouldSkipMiddleware() ? [] : $middlewares;

        array_unshift($middlewares, $coreHandler);

        return (new Pipeline($this->container))
            ->send($request)
            ->through($middlewares)
            ->then(fn() => Context::get(Response::class));
    }

    private function shouldSkipMiddleware(): bool
    {
        return (
            $this->container->bound('middleware.disable') &&
            $this->container->make('middleware.disable') === true
        );
    }
}
