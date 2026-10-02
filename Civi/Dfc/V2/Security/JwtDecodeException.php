<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Security;

/**
 * The presented token was rejected.
 *
 * Always 401 / `invalid_token`. Never 500, and never a message that says why in
 * terms an attacker can act on: the exception text is not sent to the client
 * (see {@see \Civi\Dfc\V2\Controller\Error\ErrorMapper}, which never reads
 * `$e->getMessage()`), but it IS the log line an operator reads, so it names the
 * *check that failed* in a closed vocabulary rather than echoing any part of the
 * token.
 *
 * In particular no constructor path interpolates the token, the signature, the
 * key material or the claimed `kid` into the message. `kid` is attacker-supplied
 * and lands in a log line, so the one exception is a fixed-shape note that the
 * kid was unknown; the value is available from {@see \Civi\Dfc\V2\Security\JwksCache::stats()}
 * and from the token's own header in the audit record, where it belongs.
 *
 * @package Civi\Dfc
 */
final class JwtDecodeException extends \RuntimeException implements JwtVerificationException
{
}