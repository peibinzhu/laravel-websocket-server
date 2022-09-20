<?php

declare(strict_types=1);

namespace PeibinLaravel\WebSocketServer\Listeners;

use Illuminate\Contracts\Container\Container;
use PeibinLaravel\Contracts\StdoutLoggerInterface;
use PeibinLaravel\SwooleEvent\Events\OnPipeMessage;
use PeibinLaravel\Utils\Contracts\Formatter;
use PeibinLaravel\WebSocketServer\Sender;
use PeibinLaravel\WebSocketServer\SenderPipeMessage;
use Throwable;

class OnPipeMessageListener
{
    public function __construct(
        protected Container $container,
        protected StdoutLoggerInterface $logger,
        protected Sender $sender
    ) {
    }

    public function handle(object $event): void
    {
        if ($event instanceof OnPipeMessage && $event->data instanceof SenderPipeMessage) {
            /** @var SenderPipeMessage $message */
            $message = $event->data;

            try {
                [$fd, $method] = $this->sender->getFdAndMethodFromProxyMethod($message->name, $message->arguments);
                $this->sender->proxy($fd, $method, $message->arguments);
            } catch (Throwable $exception) {
                $formatter = $this->container->get(Formatter::class);
                $this->logger->warning($formatter->format($exception));
            }
        }
    }
}
