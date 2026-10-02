<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Controller\Error;

/**
 * The `WWW-Authenticate` challenge carried by every 401 this surface produces.
 *
 * RFC 9110 §11.6.1: a 401 without a challenge is not actionable — the client
 * cannot learn which scheme to use, so it retries the same way forever. Making
 * the challenge a required, validated collaborator of {@see ProtocolError} is
 * therefore a protocol-correctness matter, not cosmetics.
 *
 * Only RFC 6750's `Bearer` scheme is implemented, and only its three closed
 * `error` codes are accepted. An `error_description` is deliberately NOT
 * modelled: it is free text, and free text in an authentication response is the
 * classic oracle for "is my token expired or is my audience wrong?". The
 * distinction a developer actually needs is already in
 * {@see ErrorCode::AUTHENTICATION_REQUIRED} vs
 * {@see ErrorCode::AUTHENTICATION_UNUSABLE}.
 *
 * The realm is an absolute https URI taken from the platform base, so it is
 * validated here rather than trusted: a realm containing a newline is a header
 * injection, and a realm containing a path is not a realm.
 *
 * @package Civi\Dfc
 */
final class AuthenticateChallenge
{
    /** RFC 6750 §3.1 error codes. Closed set; nothing else may be emitted. */
    private const ERRORS = [
        'invalid_request',
        'invalid_token',
        'insufficient_scope',
    ];

    private readonly string $realm;

    private readonly ?string $error;

    private readonly ?string $scope;

    private function __construct(string $realm, ?string $error, ?string $scope)
    {
        $this->realm = $realm;
        $this->error = $error;
        $this->scope = $scope;
    }

    /**
     * @param string      $realm Absolute https URI identifying this API. In
     *                           practice `DfcReleaseConfig::platformBaseUri()`.
     * @param string|null $error One of {@see ERRORS}; null for a plain challenge.
     * @param string|null $scope Space-separated scope names the client needs, as
     *                           RFC 6750 §3.1 allows alongside `insufficient_scope`.
     *
     * @throws \InvalidArgumentException
     */
    public static function bearer(string $realm, ?string $error = null, ?string $scope = null): self
    {
        $trimmedRealm = trim($realm);

        if (preg_match('#^https://[^\s/?\#]+(?:/[^\s?\#]*)?$#', $trimmedRealm) !== 1) {
            throw new \InvalidArgumentException(
                'A challenge realm must be an absolute https URI with no query string and no fragment. '
                . 'Use DfcReleaseConfig::platformBaseUri().'
            );
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $trimmedRealm) === 1) {
            throw new \InvalidArgumentException('A challenge realm must not contain control characters.');
        }

        if ($error !== null && !in_array($error, self::ERRORS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'An RFC 6750 challenge error must be one of: %s. Got "%s".',
                implode(', ', self::ERRORS),
                $error
            ));
        }

        $challengeScope = null;
        if ($scope !== null) {
            $challengeScope = self::validateScope($scope);
        }

        return new self($trimmedRealm, $error, $challengeScope);
    }

    public function realm(): string
    {
        return $this->realm;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    /**
     * The complete `WWW-Authenticate` field value.
     *
     * Parameter order is fixed (`realm`, `error`, `scope`) so that two
     * challenges for the same condition are byte-identical. Header caching and
     * differential testing both depend on that.
     */
    public function headerValue(): string
    {
        $value = 'Bearer realm="' . $this->realm . '"';

        if ($this->error !== null) {
            $value .= ', error="' . $this->error . '"';
        }

        if ($this->scope !== null) {
            $value .= ', scope="' . $this->scope . '"';
        }

        return $value;
    }

    private static function validateScope(string $scope): string
    {
        $trimmed = trim($scope);

        if ($trimmed === '' || preg_match('#^[A-Za-z0-9._~:/+\-]+(?: [A-Za-z0-9._~:/+\-]+)*$#', $trimmed) !== 1) {
            throw new \InvalidArgumentException(
                'A challenge scope must be space-separated scope tokens. It is echoed to the client, so it is '
                . 'restricted to the OAuth 2.0 scope-token character set.'
            );
        }

        return $trimmed;
    }
}
