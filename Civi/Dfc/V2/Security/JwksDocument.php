<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Security;

/**
 * A JWKS document: the keys as fetched, plus whatever the transport said about
 * how long they are good for.
 *
 * ============================================================================
 * WHY CACHE FRESHNESS IS AN INPUT RATHER THAN A CONSTANT
 * ============================================================================
 * RFC 7517 does not define caching for a JWKS, but every serious issuer sends
 * `Cache-Control` on the key set and intends it to be honoured. Hard-coding one
 * TTL gets it wrong in both directions: an issuer rotating keys hourly is
 * re-fetched on every request at a 24-hour TTL, and an issuer that rotates rarely
 * is re-fetched constantly at 60 seconds.
 *
 * So the transport's answer is carried in the document, and {@see JwksCache} clamps
 * it to a sane floor and ceiling. The floor is the part that matters most: an
 * issuer (or an attacker with a position to influence headers) sending
 * `max-age=0` must not be able to turn this server into a request amplifier aimed
 * at the issuer.
 *
 * ============================================================================
 * MALFORMED KEYS ARE SKIPPED, NOT FATAL
 * ============================================================================
 * A key set can contain entries this server cannot use — an EC key, an `oct`
 * symmetric key some issuers publish for other reasons, an entry with no `kid`. None
 * of those make the whole document invalid, and refusing to load a set because one
 * entry is unusable would take the API down every time an issuer adds a key type.
 *
 * So unparseable entries are dropped, and {@see skippedKeyCount()} records that
 * they were dropped so the health endpoint can report it. The counter is the point:
 * a silently-empty key set must be visible, because an empty set means every token
 * is rejected.
 *
 * @package Civi\Dfc
 */
final class JwksDocument
{
    /** RFC 7517 §5: a JWKS with more keys than this is not a key set. */
    private const MAX_KEYS = 64;

    /** @var list<JsonWebKey> */
    private readonly array $keys;

    private readonly int $skippedKeyCount;

    private readonly ?int $maxAgeSeconds;

    /**
     * @param list<JsonWebKey> $keys
     */
    private function __construct(array $keys, int $skippedKeyCount, ?int $maxAgeSeconds)
    {
        $this->keys = $keys;
        $this->skippedKeyCount = $skippedKeyCount;
        $this->maxAgeSeconds = $maxAgeSeconds;
    }

    /**
     * Build from a decoded `openid-configuration`-style JWKS response body.
     *
     * @param array<string, mixed> $decoded        The decoded JSON object.
     * @param int|null             $maxAgeSeconds   From `Cache-Control: max-age`;
     *                                              null when the transport said
     *                                              nothing usable.
     */
    public static function fromDecoded(array $decoded, ?int $maxAgeSeconds = null): self
    {
        $rawKeys = $decoded['keys'] ?? null;

        if (!is_array($rawKeys) || !array_is_list($rawKeys)) {
            throw new JwksUnavailableException(
                'The JWKS endpoint did not return a JSON object with a "keys" array. It may be an error page, '
                . 'a captive-portal response, or a proxy failure.'
            );
        }

        if (count($rawKeys) > self::MAX_KEYS) {
            throw new JwksUnavailableException(sprintf(
                'The JWKS endpoint returned %d keys, over the %d a key set may contain. This is not a signing '
                . 'key set and will not be used.',
                count($rawKeys),
                self::MAX_KEYS
            ));
        }

        $keys = [];
        $skipped = 0;

        foreach ($rawKeys as $rawKey) {
            if (!is_array($rawKey)) {
                $skipped++;

                continue;
            }

            try {
                $keys[] = JsonWebKey::fromArray($rawKey);
            } catch (\InvalidArgumentException $unusable) {
                $skipped++;
            }
        }

        return new self(
            $keys,
            $skipped,
            $maxAgeSeconds === null ? null : max(0, $maxAgeSeconds)
        );
    }

    /**
     * Build directly, for a cache warmed from a pinned file.
     *
     * @param list<JsonWebKey> $keys
     */
    public static function of(array $keys, ?int $maxAgeSeconds = null): self
    {
        return new self(array_values($keys), 0, $maxAgeSeconds === null ? null : max(0, $maxAgeSeconds));
    }

    /**
     * @return list<JsonWebKey>
     */
    public function keys(): array
    {
        return $this->keys;
    }

    public function isEmpty(): bool
    {
        return $this->keys === [];
    }

    /** How many entries were dropped because this server could not address them. */
    public function skippedKeyCount(): int
    {
        return $this->skippedKeyCount;
    }

    /** The transport's freshness hint, or null when it gave none. */
    public function maxAgeSeconds(): ?int
    {
        return $this->maxAgeSeconds;
    }
}