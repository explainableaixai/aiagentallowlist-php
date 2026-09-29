<?php

declare(strict_types=1);

namespace AIAgentAllowlist\Exception;

/**
 * Base exception for every error raised by the client.
 *
 * Carries the HTTP status (0 for transport errors) and the decoded response body.
 */
class AIAgentAllowlistException extends \RuntimeException
{
    /** @var int */
    private $status;

    /** @var array<string, mixed> */
    private $body;

    /**
     * @param array<string, mixed> $body
     */
    public function __construct(string $message, int $status = 0, array $body = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, $status, $previous);
        $this->status = $status;
        $this->body = $body;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>
     */
    public function getBody(): array
    {
        return $this->body;
    }
}
