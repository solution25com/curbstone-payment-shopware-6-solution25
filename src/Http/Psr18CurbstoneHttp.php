<?php

declare(strict_types=1);

namespace Curbstone\Http;

use Curbstone\Contract\CurbstoneHttp;
use Curbstone\Exception\CurbstonePaymentException;
use Psr\Http\Client\ClientInterface as Psr18Client;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

final class Psr18CurbstoneHttp implements CurbstoneHttp
{
    public function __construct(
        private Psr18Client $http,
        private RequestFactoryInterface $reqFactory,
        private StreamFactoryInterface $streamFactory,
        private LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function post(string $baseUrl, array $payload, int $retries, int $backoffMs): array
    {
        try {
            /** @var non-empty-string $json */
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw CurbstonePaymentException::transport($e, ['rid' => 'pre-req-json']);
        }

        $rid = $this->requestId();
        $req = $this->reqFactory->createRequest('POST', $baseUrl)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json')
            ->withHeader('X-Request-Id', $rid)
            ->withBody($this->streamFactory->createStream($json));

        $attempt = 0;
        $delayMs = max(20, $backoffMs);

        do {
            $attempt++;
            $t0 = microtime(true);

            try {
                $res  = $this->http->sendRequest($req);
                $ms   = (int) round((microtime(true) - $t0) * 1000);
                $code = $res->getStatusCode();
                $text = (string) $res->getBody();

                if ($code < 200 || $code >= 300) {
                    if ($this->retryableHttp($code) && $attempt <= $retries) {
                        $this->logger->warning('Curbstone non-2xx, retrying', [
                            'rid' => $rid, 'code' => $code, 'attempt' => $attempt, 'ms' => $ms
                        ]);
                        $this->sleepMs($delayMs);
                        $delayMs = $this->nextBackoff($delayMs);
                        continue;
                    }
                    $snippet = mb_substr($text, 0, 240);
                    $this->logger->error('Curbstone HTTP error', [
                        'rid' => $rid, 'code' => $code, 'snippet' => $snippet, 'ms' => $ms
                    ]);
                    throw CurbstonePaymentException::http($code, $snippet, ['rid' => $rid]);
                }

                /** @var array<string,mixed>|null $decoded */
                $decoded = json_decode($text, true);
                if (!is_array($decoded)) {
                    $snippet = mb_substr($text, 0, 240);
                    $this->logger->error('Curbstone parse error', ['rid' => $rid, 'snippet' => $snippet, 'ms' => $ms]);
                    throw CurbstonePaymentException::parse($snippet, ['rid' => $rid]);
                }

                $this->logger->info('Curbstone POST OK', [
                    'rid' => $rid,
                    'ms'  => $ms,
                    'code' => $code,
                    'mfr' => $decoded['MFRTRN'] ?? null,
                    'rtxt' => isset($decoded['MFRTXT']) ? mb_substr((string)$decoded['MFRTXT'], 0, 120) : null,
                ]);

                return $decoded;
            } catch (\Throwable $e) {
                $retryable = $this->isTransportRetryable($e);
                $this->logger->warning('Curbstone transport exception', [
                    'rid'     => $rid,
                    'attempt' => $attempt,
                    'retry'   => $retryable && $attempt <= $retries,
                    'error'   => $e->getMessage()
                ]);

                if ($retryable && $attempt <= $retries) {
                    $this->sleepMs($delayMs);
                    $delayMs = $this->nextBackoff($delayMs);
                    continue;
                }

                if ($e instanceof CurbstonePaymentException) {
                    throw $e;
                }
                throw CurbstonePaymentException::transport($e, ['rid' => $rid]);
            }
        } while ($attempt <= $retries);

        throw CurbstonePaymentException::transport(new \RuntimeException('exhausted retries'), ['rid' => $rid]);
    }

    private function retryableHttp(int $status): bool
    {
        return in_array($status, [408, 425, 429, 500, 502, 503, 504], true);
    }

    private function isTransportRetryable(\Throwable $e): bool
    {
        $name = get_class($e);
        foreach (['Timeout', 'Network', 'Connection', 'TooManyRequests', 'ServerException'] as $needle) {
            if (stripos($name, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    private function nextBackoff(int $prevMs): int
    {
        $next = (int) min($prevMs * 2, 2000);
        return $next + random_int(0, 100);
    }

    private function sleepMs(int $ms): void
    {
        usleep($ms * 1000);
    }

    private function requestId(): string
    {
        return bin2hex(random_bytes(8));
    }
}
