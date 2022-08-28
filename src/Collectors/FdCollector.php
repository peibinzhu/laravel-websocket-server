<?php

declare(strict_types=1);

namespace PeibinLaravel\WebSocketServer\Collectors;

class FdCollector
{
    protected static array $fds = [];

    public static function set(int $id, string $class): void
    {
        static::$fds[$id] = new Fd($id, $class);
    }

    public static function get(int $id, $default = null): ?Fd
    {
        return static::$fds[$id] ?? $default;
    }

    public static function has(int $id): bool
    {
        return isset(static::$fds[$id]);
    }

    public static function del(int $id): void
    {
        unset(static::$fds[$id]);
    }

    public static function list(): array
    {
        return static::$fds;
    }
}
