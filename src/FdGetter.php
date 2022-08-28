<?php

declare(strict_types=1);

namespace PeibinLaravel\WebSocketServer;

use Swoole\Http\Response;

class FdGetter
{
    public function get(Response $response): int
    {
        return $response->fd;
    }
}
