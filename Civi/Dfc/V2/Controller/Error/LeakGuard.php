<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Controller\Error;

/**
 * The serialiser's last line of defence: scrub a rendered payload of anything
 * that looks like server internals before it leaves the process.
 *
 * ============================================================================
 * WHY THIS EXISTS AT ALL, GIVEN THAT {@see ProtocolError} HAS NO FREE TEXT
 * ============================================================================
 * Because a guarantee nobody can *demonstrate* is a promise, not an engineering
 * control. {@see ProtocolError} is structurally incapable of carrying an
 * arbitrary string — that is the primary control and it is asserted by
 * `ProtocolErrorTest::testThrowableTextNeverReachesTheWire()`. This class is the
 * secondary control: it inspects the bytes the serialiser is about to emit and
 * replaces anything matching a high-confidence "this came from the inside"
 * signature.
 *
 * The primary control is what makes this safe to run. A denylist on its own is
 * the worst kind of security control — it blocks what you thought of and passes
 * everything else — and the patterns below would be unacceptable if arbitrary
 * text could reach them. They cannot, so the patterns only ever have to catch
 * two things:
 *
 *   1. an internal value that reached a validated slot by deliberate effort
 *      (a `DfcPath` whose tokens spell a file path, say); and
 *   2. a future edit that accidentally reintroduces a free-text field.
 *
 * ============================================================================
 * THE FALSE-POSITIVE DISCIPLINE
 * ============================================================================
 * Every pattern is chosen to be IMPOSSIBLE in a legitimate DFC payload, so the
 * guard does not damage real output:
 *
 *   - no DFC payload contains `.php` — an extension serving JSON-LD has no
 *     business naming a source file to a client;
 *   - an absolute filesystem path must be preceded by whitespace, a quote, an
 *     `=` or the start of the string, so the `/dfc/v2/semantic/address/...`
 *     inside a DFC URI can never match, while `" /var/www/x.php"` does;
 *   - `SQLSTATE`, `PDOException`, `Stack trace:` and `CRM_*` class names never
 *     appear in a DFC document, a CURIE or a header value.
 *
 * `LeakGuardTest::testLegitimateDfcPayloadsAreUntouched()` pins that, so the
 * next person who adds a pattern has to prove it does not fire on real output.
 *
 * @package Civi\Dfc
 */
final class LeakGuard
{
    /**
     * What a scrubbed run is replaced with.
     *
     * Not an empty string and not a removal: a redacted run has to stay
     * VISIBLE, otherwise a diagnostic silently loses the one piece of evidence
     * that the guard fired and the incident goes uninvestigated.
     */
    public const REDACTION = '[redacted]';

    /**
     * High-confidence "this is server internals" signatures.
     *
     * Applied in order. Ordered by specificity rather than by category so that
     * the most descriptive pattern wins and the output stays stable.
     *
     * DELIMITED WITH `~`, NOT `#`: two of these patterns contain a literal `#`
     * (a stack-frame marker and the text "Stack trace:"), and a `#` delimiter
     * would silently terminate them early and turn the remainder into PCRE
     * modifiers — a pattern that never matches, and a warning nobody reads.
     *
     * @var array<string, string>
     */
    private const SIGNATURES = [
        'PHP open tag' => '~<\?php\b~i',
        'Stack trace marker' => '~\bStack trace:~i',
        'SQLSTATE marker' => '~\bSQLSTATE\b~',
        'PDO exception class' => '~\bPDO(?:Exception|Statement|Driver|_)\w*~',
        'CiviCRM internal class' => '~\bCRM_[A-Za-z_][A-Za-z0-9_]*\b~',
        'Frame reference with line number' => '~\b#\d+\s+(?:/[^\s]*)+\.php\b~',
        'Windows path' => '~\b[A-Za-z]:\\\\[^\s"\'<>|]*~',
        'PHP source file reference' => '~\b[A-Za-z0-9_\-]+\.php\b~i',
        // Requires a FILE EXTENSION on purpose. A prefix-based rule would also
        // match `/organizations/0/name`, which is a legitimate DFC path, and
        // would then redact correct output on every 422.
        'Absolute filesystem path' => '~(?<![\w.\-/:])/(?:[A-Za-z0-9._\-]+/)+[A-Za-z0-9._\-]+\.[A-Za-z]{1,5}\b~',
    ];

    /**
     * Remove every signature match from $text.
     *
     * Idempotent: scrubbing already-scrubbed text changes nothing, so a caller
     * that scrubs twice (defensively, before logging as well as before sending)
     * gets the same bytes both times.
     */
    public static function scrub(string $text): string
    {
        foreach (self::SIGNATURES as $pattern) {
            $replaced = preg_replace($pattern, self::REDACTION, $text);
            if (is_string($replaced)) {
                $text = $replaced;
            }
        }

        return $text;
    }

    /** Would {@see scrub()} change $text? Used by tests and by the serialiser's flag. */
    public static function containsLeak(string $text): bool
    {
        return self::scrub($text) !== $text;
    }

    /**
     * The patterns, for tests and for the diagnostics endpoint sa-014 will build.
     *
     * @return array<string, string>
     */
    public static function signatures(): array
    {
        return self::SIGNATURES;
    }
}
