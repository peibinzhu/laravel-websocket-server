<?php

declare(strict_types=1);

namespace PeibinLaravel\WebSocketServer\Listeners;

use Illuminate\Contracts\Container\Container;
use PeibinLaravel\WebSocketServer\Sender;

class InitSenderListener
{
    public function __construct(protected Container $container)
    {
    }

    public function handle(object $event): void
    {
        if ($this->container->has(Sender::class)) {
            $sender = $this->container->get(Sender::class);
            $sender->setWorkerId($event->workerId);
        }
    }
}
