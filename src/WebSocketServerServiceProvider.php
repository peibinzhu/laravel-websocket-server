<?php

declare(strict_types=1);

namespace PeibinLaravel\WebSocketServer;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use PeibinLaravel\SwooleEvent\Events\AfterWorkerStart;
use PeibinLaravel\SwooleEvent\Events\OnPipeMessage;
use PeibinLaravel\WebSocketServer\Listeners\InitSenderListener;
use PeibinLaravel\WebSocketServer\Listeners\OnPipeMessageListener;

class WebSocketServerServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $dependencies = [
            Sender::class => Sender::class,
        ];
        $this->registerDependencies($dependencies);

        $listeners = [
            AfterWorkerStart::class => InitSenderListener::class,
            OnPipeMessage::class    => OnPipeMessageListener::class,
        ];
        $this->registerListeners($listeners);

        $this->registerRoute();
    }

    private function registerDependencies(array $dependencies)
    {
        $config = $this->app->get(Repository::class);
        foreach ($dependencies as $abstract => $concrete) {
            $concreteStr = is_string($concrete) ? $concrete : gettype($concrete);
            if (is_string($concrete) && method_exists($concrete, '__invoke')) {
                $concrete = function () use ($concrete) {
                    return $this->app->call($concrete . '@__invoke');
                };
            }
            $this->app->singleton($abstract, $concrete);
            $config->set(sprintf('dependencies.%s', $abstract), $concreteStr);
        }
    }

    private function registerListeners(array $listeners)
    {
        $dispatcher = $this->app->get(Dispatcher::class);
        foreach ($listeners as $event => $_listeners) {
            foreach ((array)$_listeners as $listener) {
                $dispatcher->listen($event, $listener);
            }
        }
    }

    private function registerRoute()
    {
        Router::macro('addServer', function (string $serverName, callable $callback) use (&$coll) {
            /** @var Router $this */

            // Backup http route collection.
            $httpRouters = $this->getRoutes();

            // First take the previously saved websocket routing collection from
            // the request attribute and restore it to the routing instance.
            $attributes = $this->container->get(Request::class)->attributes;
            $wsRouters = $attributes->get($serverName, new RouteCollection());
            $this->setRoutes($wsRouters);

            // After running the closure, save the latest websocket route collection
            // to the request attribute.
            $callback();
            $attributes->set($serverName, $this->getRoutes());

            // Finally restore the http route collection to the route instance.
            $this->setRoutes($httpRouters);
        });
    }
}
