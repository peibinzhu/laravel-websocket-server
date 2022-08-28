<?php

declare(strict_types=1);

namespace PeibinLaravel\WebSocketServer\Utils;

use Closure;
use Illuminate\Contracts\Container\Container;
use PeibinLaravel\Contract\StdoutLoggerInterface;
use Psr\Log\LogLevel;
use Throwable;

class SafeCaller
{
    public function __construct(protected Container $container)
    {
    }

    public function call(Closure $closure, ?Closure $default = null, string $level = LogLevel::CRITICAL): mixed
    {
        try {
            return $closure();
        } catch (Throwable $exception) {
            if ($this->container->has(StdoutLoggerInterface::class)) {
                $this->container->get(StdoutLoggerInterface::class)->log($level, (string)$exception);
            }
        }

        return value($default);
    }
}
