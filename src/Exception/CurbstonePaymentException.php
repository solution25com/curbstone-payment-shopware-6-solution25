<?php

declare(strict_types=1);

namespace Curbstone\Exception;

/**
 * @phpstan-type ContextMap array<string, mixed>
 * @phpstan-type RawMap     array<string, mixed>
 */
final class CurbstonePaymentException extends \RuntimeException
{
    /** @var ContextMap */
    private array $context;

    /**
     * @param ContextMap $context
     */
    private function __construct(string $message, array $context = [], ?\Throwable $prev = null)
    {
        parent::__construct($message, 0, $prev);
        $this->context = $context;
    }

    /**
     * @return ContextMap
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * @param ContextMap $ctx
     */
    public static function transport(\Throwable $e, array $ctx = []): self
    {
        return new self('Curbstone transport error: ' . $e->getMessage(), $ctx, $e);
    }

    /**
     * @param ContextMap $ctx
     */
    public static function http(int $status, string $bodySnippet, array $ctx = []): self
    {
        return new self("Curbstone HTTP $status: " . $bodySnippet, $ctx);
    }

    /**
     * @param ContextMap $ctx
     */
    public static function parse(string $bodySnippet, array $ctx = []): self
    {
        return new self('Curbstone parse error (non-JSON or missing fields): ' . $bodySnippet, $ctx);
    }

    /**
     * @param non-empty-string $code
     * @param ContextMap       $ctx
     * @param RawMap           $raw
     */
    public static function gateway(string $code, ?string $message = null, array $raw = [], array $ctx = []): self
    {
        $msg  = "Curbstone gateway error [$code]" . ($message ? ": $message" : '');
        $ctx2 = $ctx;
        if ($raw !== []) {
            $ctx2['raw'] = $raw;
        }
        return new self($msg, $ctx2);
    }
}
