<?php declare(strict_types=1);

namespace Curbstone\Service;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;

final class CurbstonePaymentClient
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function sendRealCharge(string $portal, array $body, bool $usingSavedCard, bool $verifyTls = true): array
    {
        try {
            $client = $this->createClient($verifyTls);

            $url = $portal . '?action=init';

            $this->logger->info('Curbstone payment request', [
                'url' => $url,
                'usingSavedCard' => $usingSavedCard,
                'request' => $this->sanitizePayload($body),
                'verifyTls' => $verifyTls,
            ]);

            $response = $client->post($url, [
                'form_params' => $body,
            ]);

            $raw = (string) $response->getBody();
            $data = json_decode($raw, true);

            $this->logger->info('Curbstone payment response', [
                'url' => $url,
                'usingSavedCard' => $usingSavedCard,
                'httpStatus' => $response->getStatusCode(),
                'response' => \is_array($data) ? $this->sanitizePayload($data) : ['raw' => $raw],
            ]);

            return \is_array($data) ? $data : [];
        } catch (\Throwable $e) {
            $this->logger->error('Curbstone payment HTTP error', [
                'url' => $portal . '?action=init',
                'usingSavedCard' => $usingSavedCard,
                'request' => $this->sanitizePayload($body),
                'exception' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Curbstone charge HTTP error: ' . $e->getMessage(), 0, $e);
        }
    }
    public function sendRefund(string $portal, array $body, bool $verifyTls = true): array
    {
        return $this->doRequest($portal, $body, 'Curbstone refund HTTP error', $verifyTls);
    }

    public function sendVoid(string $portal, array $body, bool $verifyTls = true): array
    {
        return $this->doRequest($portal, $body, 'Curbstone void HTTP error', $verifyTls);
    }

    private function doRequest(string $portal, array $body, string $errorPrefix, bool $verifyTls): array
    {
        try {
            $client = $this->createClient($verifyTls);

            $url = $portal . '?action=init';

            $this->logger->info('Curbstone payment request', [
                'url' => $url,
                'request' => $this->sanitizePayload($body),
                'verifyTls' => $verifyTls,
                'operation' => $errorPrefix,
            ]);

            $response = $client->post($url, [
                'form_params' => $body,
            ]);

            $raw = (string) $response->getBody();
            $data = json_decode($raw, true);

            $this->logger->info('Curbstone payment response', [
                'url' => $url,
                'operation' => $errorPrefix,
                'httpStatus' => $response->getStatusCode(),
                'response' => \is_array($data) ? $this->sanitizePayload($data) : ['raw' => $raw],
            ]);

            return \is_array($data) ? $data : [];
        } catch (\Throwable $e) {
            $this->logger->error('Curbstone payment HTTP error', [
                'url' => $portal . '?action=init',
                'operation' => $errorPrefix,
                'request' => $this->sanitizePayload($body),
                'exception' => $e->getMessage(),
            ]);

            throw new \RuntimeException($errorPrefix . ': ' . $e->getMessage(), 0, $e);
        }
    }

    private function createClient(bool $verifyTls): Client
    {
        $options = ['timeout' => 10];
        if (!$verifyTls) {
            $options['verify'] = false;
        }

        return new Client($options);
    }

    private function sanitizePayload(array $payload): array
    {
        $sensitiveKeys = [
            'MFCARD',
            'MFCRD',
            'MFCRD4',
            'MFCRD6',
            'MFCCVV',
            'MFRCVV',
            'MFCCVC',
            'MFEXP1',
            'MFEXP2',
            'MFEXP',
            'MFEDAT',
            'MFKEYP',
            'MFSESS',
            'MFRTRN',
            'MPCUSF',
            'MPTRGT',
            'cardHolderName',
            'cardHolderFirstName',
            'cardHolderLastName',
        ];

        foreach ($sensitiveKeys as $key) {
            if (array_key_exists($key, $payload)) {
                $payload[$key] = '[redacted]';
            }
        }

        return $payload;
    }
}
