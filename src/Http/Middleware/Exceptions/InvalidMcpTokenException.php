<?php

declare(strict_types=1);

namespace Authkit\Authkit\Http\Middleware\Exceptions;

use RuntimeException;

/**
 * Internal-only signal for the authkit.mcp middleware's own iss/aud policy
 * checks — caught inside AuthenticateMcpToken::handle() and turned into an
 * RFC 6750 401 challenge; never escapes the middleware into consumer code.
 */
final class InvalidMcpTokenException extends RuntimeException
{
    public static function wrongIssuer(): self
    {
        return new self('MCP bearer token carries an iss claim that does not match the configured AuthKit domain.');
    }

    public static function wrongAudience(): self
    {
        return new self('MCP bearer token carries an aud claim that does not match the configured resource indicator.');
    }
}
