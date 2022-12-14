<?php

declare(strict_types=1);

namespace PeibinLaravel\WebSocketServer;

use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use PeibinLaravel\SwooleEvent\Events\AfterWorkerStart;
use PeibinLaravel\SwooleEvent\Events\OnPipeMessage;
use PeibinLaravel\Utils\Providers\RegisterProviderConfig;
use PeibinLaravel\WebSocketServer\Listeners\InitSenderListener;
use PeibinLaravel\WebSocketServer\Listeners\OnPipeMessageListener;

class WebSocketServerServiceProvider extends ServiceProvider
{
    use RegisterProviderConfig;

    public function __invoke(): array
    {
        $this->registerRoute();

        $this->app->singleton(Sender::class);

        return [
            'listeners' => [
                AfterWorkerStart::class => InitSenderListener::class,
                OnPipeMessage::class    => OnPipeMessageListener::class,
            ],
        ];
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
