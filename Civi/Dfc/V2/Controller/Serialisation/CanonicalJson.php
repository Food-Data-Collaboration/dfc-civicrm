<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Controller\Serialisation;

/**
 * Deterministic JSON encoding, for representations whose bytes must be
 * reproducible.
 *
 * ============================================================================
 * WHY THIS EXISTS INSTEAD OF `json_encode($data)`
 * ============================================================================
 * An HTTP entity tag is a claim about BYTES: "if you have these bytes, this is
 * the representation". PHP's `json_encode` output is not stable in the way a
 * validator needs:
 *
 *   - associative array key order is insertion order, so a code change that
 *     assembles the same properties in a different order produces a different
 *     ETag for the same resource — every client that cached the old tag sees a
 *     spurious change;
 *   - float rendering (`1.0` vs `1`) and `/` and unicode escaping all vary with
 *     flags, and flags are a per-call-site decision, so two call sites can emit
 *     different bytes for the same document.
 *
 * So: object keys sorted by byte value, list order preserved, no insignificant
 * whitespace, `/` and non-ASCII left unescaped, zero fractions preserved. Given
 * the same value, the same bytes — every time, on every machine.
 *
 * ============================================================================
 * WHAT THIS IS NOT
 * ============================================================================
 * This is NOT JSON-LD expansion or compaction. It knows nothing about `@context`,
 * CURIEs or blank nodes; it is a canonical JSON writer that the JSON-LD
 * serialiser happens to call. PRD-002 §1 rules out hand-writing expansion, and
 * nothing here does.
 *
 * ============================================================================
 * OBJECTS VERSUS ARRAYS
 * ============================================================================
 * PHP cannot distinguish an empty JSON object from an empty JSON array: both are
 * `[]`. This class encodes `[]` as `[]`, because in this layer the only places
 * an empty collection legitimately occurs are `ldp:contains` (which is an RDF
 * set and is always an array) and `@context` (never empty). A caller that needs
 * `{}` passes `new \stdClass()`, which is handled explicitly.
 *
 * @package Civi\Dfc
 */
final class CanonicalJson
{
    private function __construct()
    {
        // Static utility.
    }

    /**
     * Encode $data canonically.
     *
     * @param mixed $data Arrays, lists and scalars, or `\stdClass` for an object.
     *
     * @throws \JsonException on invalid UTF-8, INF/NAN, or a resource.
     */
    public static function encode(mixed $data): string
    {
        return json_encode(
            self::canonicalise($data),
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION
            | JSON_THROW_ON_ERROR
        );
    }

    /**
     * Recursively sort object keys, leaving list order alone.
     *
     * `sort()` with `SORT_STRING` rather than `ksort()`'s default flags: key
     * order in the output must be a function of the key BYTES, not of PHP's
     * numeric-string coercion, which would put `"10"` before `"9"` on one build
     * and differently on another.
     */
    private static function canonicalise(mixed $value): mixed
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map([self::class, 'canonicalise'], $value);
            }

            $keys = array_map('strval', array_keys($value));
            sort($keys, SORT_STRING);

            $sorted = [];
            foreach ($keys as $key) {
                $sorted[$key] = self::canonicalise($value[$key]);
            }

            return $sorted;
        }

        if ($value instanceof \stdClass) {
            $properties = get_object_vars($value);
            $keys = array_map('strval', array_keys($properties));
            sort($keys, SORT_STRING);

            $sorted = new \stdClass();
            foreach ($keys as $key) {
                $sorted->{$key} = self::canonicalise($properties[$key]);
            }

            return $sorted;
        }

        if (is_float($value) && !is_finite($value)) {
            throw new \JsonException(
                'INF and NAN have no JSON representation. A value like that in a DFC document is a defect '
                . 'in the producer, and encoding it as null would silently change the document.'
            );
        }

        return $value;
    }
}
