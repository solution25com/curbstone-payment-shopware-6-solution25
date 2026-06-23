<?php declare(strict_types=1);

namespace Curbstone\Service;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;

final class CurbstonePreauthClient
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function sendPreauthInit(string $portal, array $body, bool $verifyTls = true): array
    {
        $url = $portal . '?action=init';

        try {
            $client = $this->createClient($verifyTls);

            $this->logger->info('Curbstone preauth request', [
                'url' => $url,
                'request' => $this->sanitizePayload($body),
                'verifyTls' => $verifyTls,
            ]);

            $response = $client->post($url, [
                'form_params' => $body,
            ]);

            $raw = (string) $response->getBody();
            $result = json_decode($raw, true);

            $this->logger->info('Curbstone preauth response', [
                'url' => $url,
                'response' => \is_array($result) ? $this->sanitizePayload($result) : ['raw' => $raw],
                'httpStatus' => $response->getStatusCode(),
            ]);

            return \is_array($result) ? $result : [];
        } catch (\Throwable $e) {
            $this->logger->error('Curbstone preauth HTTP error', [
                'url' => $url,
                'request' => $this->sanitizePayload($body),
                'exception' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Curbstone 0-auth HTTP error: ' . $e->getMessage(), 0, $e);
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
