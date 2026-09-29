<?php

declare(strict_types=1);

namespace AIAgentAllowlist\Exception;

/**
 * HTTP 403: the account is not activated or the lookup quota is exhausted.
 */
class QuotaException extends AIAgentAllowlistException
{
}
