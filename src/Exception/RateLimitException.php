<?php

declare(strict_types=1);

namespace AIAgentAllowlist\Exception;

/**
 * HTTP 429: too many requests. Raised only after the client's own retries are used up.
 */
class RateLimitException extends AIAgentAllowlistException
{
}
