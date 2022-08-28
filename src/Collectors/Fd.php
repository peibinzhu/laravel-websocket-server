<?php

declare(strict_types=1);

namespace PeibinLaravel\WebSocketServer\Collectors;

class Fd
{
    public function __construct(public int $fd, public string $class)
    {
    }
}
