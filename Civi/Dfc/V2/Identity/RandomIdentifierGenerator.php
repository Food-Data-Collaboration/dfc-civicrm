<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Identity;

/**
 * The production {@see IdentifierGeneratorInterface}: 128 bits of CSPRNG output
 * encoded as 26-character Crockford base32.
 *
 * ============================================================================
 * WHY CROCKFORD BASE32 AND NOT A UUID
 * ============================================================================
 *
 * - URI-safe with no encoding: Crockford's alphabet is `0-9` plus `ABCDEFGHJKMNPQRSTVWXYZ`,
 *   so the output can never contain a character that changes meaning in a URI
 *   path (no `/`, `#`, `?`, `%`, and no case-folding hazard).
 * - Case-insensitive-safe in the ways that matter: it contains no `I`, `L`,
 *   `O` or `U`, so a human transcribing it from a support ticket into a search
 *   box cannot produce a different identifier.
 * - Fixed 26 characters for 128 bits (log2(32) = 5, ceil(128/5) = 26), so
 *   identifier length is constant and a length check cannot be used to infer
 *   anything.
 * - Not a UUID string: no dashes, no version nibble. There is no RFC 4122 version
 *   to lie about, and therefore no version-4 "random" claim for a downstream
 *   consumer to over-trust.
 *
 * WHY NOT TIME-ORDERED
 * See {@see IdentifierGeneratorInterface}: creation time would be readable from
 * a public identifier.
 *
 * NOTE ON THE SOURCE OF RANDOMNESS
 * `random_bytes()` reads the kernel CSPRNG and throws on failure. A failing
 * CSPRNG is not something to paper over with `uniqid()` or `mt_rand()`: silently
 * downgrading the entropy source of a public identity is worse than failing the
 * request. Letting the exception propagate is the correct behaviour.
 *
 * @package Civi\Dfc
 */
final class RandomIdentifierGenerator implements IdentifierGeneratorInterface
{
    /** Crockford base32: no I, L, O or U. */
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private const RANDOM_BYTES = 16;

    private const LENGTH = 26;

    public function generate(string $kind): string
    {
        // $kind is part of the interface but deliberately unused: partitioning
        // the entropy source by entity kind would let a reader of public
        // identifiers count records of one kind, which is a small leak for no
        // benefit. Implementations that need partitioning may do it privately.
        unset($kind);

        $random = random_bytes(self::RANDOM_BYTES);

        $identifier = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $identifier .= self::ALPHABET[ord($random[$i % self::RANDOM_BYTES]) % 32];
        }

        return $identifier;
    }
}