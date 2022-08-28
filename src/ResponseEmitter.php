<?php

declare(strict_types=1);

namespace PeibinLaravel\WebSocketServer;

use DateTime;
use Illuminate\Http\Response as IlluminateResponse;
use PeibinLaravel\Server\Contracts\ResponseEmitterInterface;
use Swoole\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

class ResponseEmitter implements ResponseEmitterInterface
{
    /**
     * @param SymfonyResponse|IlluminateResponse $response
     * @param Response                           $connection
     * @param bool                               $withContent
     */
    public function emit(
        SymfonyResponse | IlluminateResponse $response,
        mixed $connection,
        bool $withContent = true
    ): void {
        try {
            if (strtolower($connection->header['Upgrade'] ?? '') === 'websocket') {
                return;
            }

            $this->buildSwooleResponse($connection, $response);
            $content = $response->getContent();
            if ($withContent) {
                $connection->end((string)$content);
            } else {
                $connection->end();
            }
        } catch (Throwable) {
        }
    }

    protected function buildSwooleResponse(
        Response $swooleResponse,
        SymfonyResponse | IlluminateResponse $response
    ): void {
        // Headers
        if (!$response->headers->has('Date')) {
            $response->setDate(DateTime::createFromFormat('U', (string)time()));
        }

        $headers = $response->headers->allPreserveCaseWithoutCookies();

        foreach ($headers as $name => $values) {
            foreach ($values as $value) {
                $swooleResponse->header($name, $value);
            }
        }

        // Cookies
        foreach ($response->headers->getCookies() as $cookie) {
            $swooleResponse->{$cookie->isRaw() ? 'rawcookie' : 'cookie'}(
                $cookie->getName(),
                $cookie->getValue(),
                $cookie->getExpiresTime(),
                $cookie->getPath(),
                $cookie->getDomain() ?? '',
                $cookie->isSecure(),
                $cookie->isHttpOnly(),
                $cookie->getSameSite(),
            );
        }

        // Trailers
        if (method_exists($response, 'getTrailers') && method_exists($swooleResponse, 'trailer')) {
            foreach ($response->getTrailers() ?? [] as $key => $value) {
                $swooleResponse->trailer($key, $value);
            }
        }

        // Status code
        $reason = method_exists($response, 'statusText') ? $response->statusText() : '';
        $swooleResponse->status($response->getStatusCode(), $reason);
    }
}
