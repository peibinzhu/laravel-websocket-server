<?php

declare(strict_types=1);

namespace PeibinLaravel\WebSocketServer;

use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use PeibinLaravel\Utils\Providers\RegisterProviderConfig;

class WebSocketServerServiceProvider extends ServiceProvider
{
    use RegisterProviderConfig;

    public function __invoke(): array
    {
        $this->registerRoute();
        return [];
    }

    private function registerRoute()
    {
        Router::macro('addServer', function (string $serverName, callable $callback) use (&$coll) {
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
