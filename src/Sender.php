<?php

declare(strict_types=1);

namespace PeibinLaravel\WebSocketServer;

use PeibinLaravel\WebSocketServer\Exceptions\InvalidMethodException;
use Swoole\Server;
use Illuminate\Contracts\Container\Container;
use PeibinLaravel\Contracts\StdoutLoggerInterface;

/**
 * @method push(int $fd, $data, int $opcode = null, $finish = null)
 * @method disconnect(int $fd, int $code = null, string $reason = null)
 */
class Sender
{
    protected ?int $workerId = null;

    public function __construct(protected Container $container, protected StdoutLoggerInterface $logger)
    {
    }

    public function __call($name, $arguments)
    {
        [$fd, $method] = $this->getFdAndMethodFromProxyMethod($name, $arguments);

        if (!$this->proxy($fd, $method, $arguments)) {
            $this->sendPipeMessage($name, $arguments);
        }
    }

    public function proxy(int $fd, string $method, array $arguments): bool
    {
        $result = $this->check($fd);
        if ($result) {
            /** @var \Swoole\WebSocket\Server $server */
            $server = $this->getServer();
            $server->{$method}(...$arguments);
            $this->logger->debug("[WebSocket] Worker.{$this->workerId} send to #{$fd}");
        }

        return $result;
    }

    public function setWorkerId(int $workerId): void
    {
        $this->workerId = $workerId;
    }

    public function check($fd): bool
    {
        $info = $this->getServer()->connection_info($fd);

        if (($info['websocket_status'] ?? null) === WEBSOCKET_STATUS_ACTIVE) {
            return true;
        }

        return false;
    }

    public function getFdAndMethodFromProxyMethod(string $method, array $arguments): array
    {
        if (!in_array($method, ['push', 'disconnect'])) {
            throw new InvalidMethodException(sprintf('Method [%s] is not allowed.', $method));
        }

        return [(int)$arguments[0], $method];
    }

    protected function getServer(): Server
    {
        return $this->container->get(Server::class);
    }

    protected function sendPipeMessage(string $name, array $arguments): void
    {
        $server = $this->getServer();
        $workerCount = $server->setting['worker_num'] - 1;
        for ($workerId = 0; $workerId <= $workerCount; ++$workerId) {
            if ($workerId !== $this->workerId) {
                $server->sendMessage(new SenderPipeMessage($name, $arguments), $workerId);
                $this->logger->debug("[WebSocket] Let Worker.{$workerId} try to {$name}.");
            }
        }
    }
}
