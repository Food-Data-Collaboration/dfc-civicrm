<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Security;

/**
 * The key set: selection by `kid` AND `alg`, with no guessing.
 *
 * ============================================================================
 * SELECTION RULES, AND WHY EACH ONE IS A RULE AND NOT A `??`
 * ============================================================================
 * A JWKS routinely holds several keys at once. The live target realm holds two: an
 * `enc` key (`alg: RSA-OAEP`) and a `sig` key (`alg: RS256`) — see
 * {@see JsonWebKey}. So:
 *
 *   1. CANDIDATES are keys that are signature keys AND serve the requested
 *      algorithm. Filtering on both is what stops the encryption key being chosen.
 *   2. `kid` PRESENT: exactly one candidate with that `kid` is used. If there is no
 *      such candidate the result is null, which is what triggers
 *      {@see JwksCache}'s refresh-and-retry. More than one candidate with the same
 *      `kid` is an issuer-side key reuse; the FIRST is used and
 *      {@see duplicateKidCount()} makes it visible, because silently preferring one
 *      of two keys that claim the same identity is exactly the kind of ambiguity a
 *      verifier must not resolve on the attacker's behalf.
 *   3. `kid` ABSENT: the key is used ONLY if there is exactly one candidate. RFC
 *      7515 §4.1.4 makes `kid` optional, so a single-key set must still verify —
 *      but choosing among two keys with no `kid` is a coin toss that decides
 *      whether a signature verifies, and a coin toss is not a security decision.
 *
 * ============================================================================
 * MERGING, WHICH IS WHAT "HANDLES OVERLAPPING KEY SETS" MEANS HERE
 * ============================================================================
 * During a rotation the two key sets overlap: the same `kid` may be present in both,
 * or a new `kid` is added before the old one is dropped. {@see mergedWith()} is
 * used by {@see JwksCache} on refresh and resolves a collision in favour of the
 * NEW document, because a JWKS is the issuer's authoritative current statement.
 *
 * What it deliberately does NOT do is keep old keys alive after the issuer has
 * dropped them. Retaining a retired key would let a compromised signing key keep
 * validating tokens until the process restarts — and a JWKS that removes a key is
 * the issuer telling us the key is done. The overlap that DOES matter is handled by
 * the refresh itself: a token signed with a key that arrived mid-window triggers a
 * refresh and succeeds, and a token signed with a key the issuer has since dropped
 * is rejected once the refreshed set no longer contains it.
 *
 * @package Civi\Dfc
 */
final class JsonWebKeySet
{
    /** @var list<JsonWebKey> */
    private readonly array $keys;

    /**
     * @param list<JsonWebKey> $keys
     */
    private function __construct(array $keys)
    {
        $this->keys = array_values($keys);
    }

    /**
     * @param iterable<JsonWebKey> $keys
     */
    public static function of(iterable $keys): self
    {
        $list = [];
        foreach ($keys as $key) {
            $list[] = $key;
        }

        return new self($list);
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

    public function count(): int
    {
        return count($this->keys);
    }

    /**
     * The key ids in this set, SORTED.
     *
     * Sorted rather than in document order because the only consumer is
     * {@see duplicateKidCount()} and the diagnostics surface, and both want a stable
     * answer: an issuer reordering its key set would otherwise make the diagnostics
     * output change for no reason. Selection itself is by `kid` lookup, never by
     * position, so the order has no security meaning.
     *
     * @return list<string>
     */
    public function kids(): array
    {
        $kids = array_map(
            static fn (JsonWebKey $key): string => $key->kid(),
            $this->keys
        );
        sort($kids, \SORT_STRING);

        /** @var list<string> $kids */
        return array_values($kids);
    }

    /**
     * Select the verification key for a token, or null when this set has none.
     *
     * @param string|null $kid       The token header's `kid`, or null.
     * @param string      $algorithm The algorithm the token claims.
     */
    public function resolve(?string $kid, string $algorithm): ?JsonWebKey
    {
        $candidates = [];
        foreach ($this->keys as $key) {
            if ($key->isSignatureKey() && $key->serves($algorithm)) {
                $candidates[] = $key;
            }
        }

        if ($candidates === []) {
            return null;
        }

        if ($kid !== null && $kid !== '') {
            foreach ($candidates as $candidate) {
                if (hash_equals($candidate->kid(), $kid)) {
                    return $candidate;
                }
            }

            return null;
        }

        // No kid: exactly one usable candidate is still unambiguous.
        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /**
     * How many `kid` values appear more than once.
     *
     * Zero in a healthy set. Non-zero means the issuer published two keys claiming
     * one identity, which {@see resolve()} resolves silently and the diagnostics
     * surface reports. Always zero for the sets the target realm publishes.
     */
    public function duplicateKidCount(): int
    {
        $counts = array_count_values($this->kids());
        $duplicates = 0;
        foreach ($counts as $count) {
            if ($count > 1) {
                $duplicates += $count - 1;
            }
        }

        return $duplicates;
    }

    /**
     * This set with $newer laid over it.
     *
     * Used on refresh so that a caller holding a key from the previous set can
     * still find it while the two sets overlap, while a key the new set omits is
     * still absent from the merged result if it was never in this one. See the
     * class docblock for why nothing is retained beyond the newer document.
     */
    public function mergedWith(self $newer): self
    {
        /** @var array<string, JsonWebKey> $byKid */
        $byKid = [];
        foreach ($this->keys as $key) {
            $byKid[$key->kid()] = $key;
        }

        foreach ($newer->keys as $key) {
            $byKid[$key->kid()] = $key;
        }

        return new self(array_values($byKid));
    }

    /**
     * Audit-safe. No key material.
     *
     * @return array<int, array<string, string|bool>>
     */
    public function describe(): array
    {
        return array_map(
            static fn (JsonWebKey $key): array => $key->describe(),
            $this->keys
        );
    }
}