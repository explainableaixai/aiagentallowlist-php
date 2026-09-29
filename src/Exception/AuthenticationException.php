<?php

declare(strict_types=1);

namespace AIAgentAllowlist\Exception;

/**
 * HTTP 401: the X-API-Key header was missing or the key is unknown.
 */
class AuthenticationException extends AIAgentAllowlistException
{
}
