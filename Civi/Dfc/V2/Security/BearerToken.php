<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Security;

/**
 * One extracted bearer token, wrapped so it cannot be logged by accident.
 *
 * ============================================================================
 * WHY A VALUE OBJECT FOR A STRING
 * ============================================================================
 * Because the alternative is a bare `string $token` travelling through four
 * constructors and one array, and a bare string has no way to refuse being
 * interpolated. `sprintf('Bearer %s', $token)` in a debug line, or
 * `$context['token'] = $token`, and the credential is in the log — which is exactly
 * what PRD-002 §9 forbids: "Never log access/refresh tokens, client secrets,
 * authorization headers".
 *
 * The two defences are:
 *   - there is no `__toString()`, so `$token` cannot reach a string context without
 *     someone writing `->value()` on purpose; and
 *   - {@see __debugInfo()} redacts, so `print_r()` and `var_dump()` — which is what a
 *     developer actually does when debugging a request — show `[REDACTED]`.
 *
 * {@see value()} remains public because the decoder needs the bytes. A method that
 * is only reachable by name is a small speed bump, and a small speed bump is what
 * stops an accident.
 *
 * ============================================================================
 * THE ONE GAP, STATED RATHER THAN HIDDEN
 * ============================================================================
 * `var_export()` does NOT consult `__debugInfo()`, so it prints the raw value. PHP
 * offers no hook for it. That is acceptable here because `var_export` on an OBJECT is
 * a deliberate act for a value that is obviously not scalar — nobody reaches for it to
 * print a bearer token in a log — whereas `var_dump` is reached for reflexively and is
 * covered.
 *
 * The residual is a secret held in a plain property, so anything that reflects over
 * object properties (a serialiser, a debugger's variable inspector, a crash dump)
 * shows it. The control here is against the casual and near-casual paths; it is not a
 * guarantee against an attacker who can read process memory, who does not need it.
 *
 * @package Civi\Dfc
 */
final class BearerToken
{
    /** What a redacted token looks like in a dump. Matches AGENTS.md's convention. */
    public const REDACTION = '[REDACTED]';

    private readonly string $token;

    private function __construct(string $token)
    {
        $this->token = $token;
    }

    /**
     * Wrap a token already known to satisfy RFC 6750's `b64token` grammar.
     *
     * Public because {@see BearerTokenExtractor} is a different class; the
     * validation it has performed is documented on that class rather than repeated
     * here.
     */
    public static function of(string $token): self
    {
        return new self($token);
    }

    /**
     * The compact JWS. Call this only to hand the token to a verifier.
     */
    public function value(): string
    {
        return $this->token;
    }

    public function byteLength(): int
    {
        return strlen($this->token);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['token' => self::REDACTION];
    }

    /**
     * Deliberately NO `__toString()`.
     *
     * A secret that stringifies itself is one `sprintf('%s', $secret)` away from a
     * log line, an exception message or a response body. Making the conversion
     * explicit means every appearance of the raw bytes in a string context is a line
     * somebody typed.
     */
}