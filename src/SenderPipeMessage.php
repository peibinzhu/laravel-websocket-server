<?php

declare(strict_types=1);

namespace PeibinLaravel\WebSocketServer;

class SenderPipeMessage
{
    public function __construct(public string $name, public array $arguments)
    {
    }
}
