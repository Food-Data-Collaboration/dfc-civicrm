<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Security;

/**
 * The production fetcher: one HTTPS GET, with the transport pinned down.
 *
 * ============================================================================
 * WHY `file_get_contents` AND NOT CURL
 * ============================================================================
 * PHP's HTTP stream wrapper does the same job with no dependency, and the failure
 * modes are all handled below anyway. The wrapper's `ssl.*` options are what
 * actually matter here, and they are set explicitly rather than inherited: a
 * deployment with a broken `php.ini` that turns peer verification off must not
 * silently change this extension's security, so the safe values are written into
 * the context this class builds.
 *
 * ============================================================================
 * REDIRECTS ARE NOT FOLLOWED
 * ============================================================================
 * `follow_location => 0`. A JWKS endpoint that redirects is one of three things: a
 * misconfiguration, a proxy rewriting http to https, or an interception. Following
 * it means the verification keys for every token on this interface come from
 * wherever the redirect led. An operator who genuinely needs a redirect can fix the
 * endpoint URL, which is a change in the open and not a silent one.
 *
 * ============================================================================
 * WHAT THIS DOES NOT LOG
 * ============================================================================
 * Nothing. No key material, no token, no response body. Failures produce a
 * {@see JwksUnavailableException} whose message names the transport condition and
 * nothing else, because {@see \Civi\Dfc\V2\Controller\Error\ErrorMapper} turns the
 * message into a 500 detail that does not read it — but an operator reading a
 * PHP log will, and an issuer's error page can contain a request id this deployment
 * considers internal.
 *
 * @package Civi\Dfc
 */
final class HttpJwksFetcher implements JwksFetcherInterface
{
    private readonly int $timeoutSeconds;

    private readonly int $maxResponseBytes;

    public function __construct(int $timeoutSeconds = 5, int $maxResponseBytes = 262144)
    {
        if ($timeoutSeconds < 1 || $timeoutSeconds > 60) {
            throw new \InvalidArgumentException(
                'The JWKS fetch timeout must be between 1 and 60 seconds. A longer one turns a slow IdP into '
                . 'a slow API: every request that needs a key refresh waits on it.'
            );
        }

        if ($maxResponseBytes < 1024) {
            throw new \InvalidArgumentException(
                'The JWKS response size limit must be at least 1 KiB. A key set is a few kilobytes; a larger '
                . 'body is an error page.'
            );
        }

        $this->timeoutSeconds = $timeoutSeconds;
        $this->maxResponseBytes = $maxResponseBytes;
    }

    public function fetch(string $uri): JwksDocument
    {
        if (preg_match('#^https://[^\s/?\#]+(?::\d+)?(?:/[^\s?\#]*)?$#', $uri) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'The JWKS URI must be an absolute https URI with no query string. Got something else. Refusing '
                . 'to fetch signing keys over anything else.',
                $uri
            ));
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                // Accept a JSON key set; nothing else is negotiated here.
                'header' => "Accept: application/json\r\nUser-Agent: dfc-civicrm/1 jwks\r\n",
                'timeout' => $this->timeoutSeconds,
                // A redirect means the keys would come from somewhere else.
                'follow_location' => 0,
                'max_redirects' => 0,
                'ignore_errors' => true,
                'protocol_version' => 1.1,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'SNI_enabled' => true,
            ],
        ]);

        // `$http_response_header` is populated by the HTTP stream wrapper in the
        // LOCAL scope of the call. Renaming rather than assuming it exists is what
        // keeps this working if the wrapper is ever replaced.
        $responseHeaders = [];
        $body = @file_get_contents($uri, false, $context, 0, $this->maxResponseBytes);

        if (isset($http_response_header) && is_array($http_response_header)) {
            $responseHeaders = $http_response_header;
        }

        if ($body === false) {
            throw new JwksUnavailableException(sprintf(
                'The signing keys could not be fetched from the issuer. The request to the JWKS endpoint failed '
                . 'at the transport level or took longer than %d seconds. This server will not accept tokens it '
                . 'cannot verify, so this is a server-side failure rather than a rejection of the token.',
                $this->timeoutSeconds
            ));
        }

        if (strlen($body) > $this->maxResponseBytes) {
            throw new JwksUnavailableException(
                'The JWKS endpoint returned a body larger than the size this server will read. That is not a key '
                . 'set, and reading it anyway would be a denial-of-service surface.'
            );
        }

        $status = self::statusFrom($responseHeaders);
        if ($status !== null && ($status < 200 || $status >= 300)) {
            throw new JwksUnavailableException(sprintf(
                'The issuer\'s JWKS endpoint answered HTTP %d. Signing keys could not be obtained, so tokens '
                . 'cannot be verified.',
                $status
            ));
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $notJson) {
            throw new JwksUnavailableException(
                'The JWKS endpoint answered with a body that is not JSON. It may be an error page or a proxy '
                . 'response, and its content is not logged.'
            );
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new JwksUnavailableException(
                'The JWKS endpoint answered with a JSON value that is not an object.'
            );
        }

        /** @var array<string, mixed> $decoded */
        return JwksDocument::fromDecoded($decoded, self::maxAgeFrom($responseHeaders));
    }

    /**
     * @param list<string> $responseHeaders
     */
    private static function statusFrom(array $responseHeaders): ?int
    {
        foreach ($responseHeaders as $line) {
            if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})\b#', $line, $m) === 1) {
                $status = (int) $m[1];

                // With HTTP/1.1 and redirects disabled there is one status line. If
                // a proxy added one, the LAST status is the effective one.
                $last = $status;
            }
        }

        return isset($last) ? $last : null;
    }

    /**
     * Extract `max-age` from `Cache-Control`, or from `Expires` as a fallback.
     *
     * @param list<string> $responseHeaders
     */
    private static function maxAgeFrom(array $responseHeaders): ?int
    {
        $maxAge = null;
        $expires = null;

        foreach ($responseHeaders as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $name = strtolower(trim($name));
                $value = trim($value);

                if ($name === 'cache-control' && $maxAge === null) {
                    if (preg_match('/(?:^|[\s,])max-age\s*=\s*"?(\d+)"?/i', $value, $m) === 1) {
                        $maxAge = (int) $m[1];
                    }

                    continue;
                }

                if ($name === 'expires' && $expires === null) {
                    $expires = $value;
                }
            }
        }

        if ($maxAge !== null) {
            return $maxAge;
        }

        if ($expires === null) {
            return null;
        }

        $timestamp = strtotime($expires);
        if ($timestamp === false) {
            return null;
        }

        return max(0, $timestamp - time());
    }
}