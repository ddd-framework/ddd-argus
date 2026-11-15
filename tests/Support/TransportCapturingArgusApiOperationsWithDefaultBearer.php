<?php

declare(strict_types=1);

namespace DDD\Tests\Argus\Support;

/**
 * The capturing batch with a fixed bearer token of the default CLI account (no account lookup).
 */
class TransportCapturingArgusApiOperationsWithDefaultBearer extends TransportCapturingArgusApiOperations
{
    public const string DEFAULT_ACCOUNT_TOKEN = 'default-account-token';

    protected static function isSendingBearerTokenOfDefaultAccount(): bool
    {
        return true;
    }

    protected static function createBearerTokenOfDefaultAccount(): ?string
    {
        return self::DEFAULT_ACCOUNT_TOKEN;
    }
}
