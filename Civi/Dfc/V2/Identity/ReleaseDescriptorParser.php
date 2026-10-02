<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Identity;

use Civi\Dfc\V2\Identity\Exception\MalformedReleaseDescriptorException;

/**
 * A deliberately restricted reader for `dfc-release.yaml`-shaped files.
 *
 * ============================================================================
 * WHY THIS EXISTS AT ALL
 * ============================================================================
 * The extension has no YAML dependency and is not allowed to gain one (an
 * extension must install without an administrator running Composer inside the
 * checkout). CiviCRM core ships no YAML parser and PHP has no ext-yaml. So the
 * release descriptor needs a reader.
 *
 * The alternative — hand-transcribing the pins into PHP — is exactly what
 * PRD-002 §5 forbids ("config/dfc-release.yaml ... source the DFC version/context
 * namespace from here, never hand-coded per mapper") and would rot on the next
 * DFC release.
 *
 * ============================================================================
 * WHAT IT SUPPORTS (the whole grammar, exhaustively)
 * ============================================================================
 *   - comments: a `#` that is at the start of a line or preceded by whitespace,
 *     outside quotes;
 *   - blank lines;
 *   - block mappings nested by indentation, spaces only;
 *   - block sequences of SCALARS (`- value`);
 *   - scalars: plain, single-quoted (`''` escapes), double-quoted
 *     (`\n \t \r \\ \" \/ \uXXXX`);
 *   - every scalar is a string. There is no type system here; consumers validate.
 *
 * ============================================================================
 * WHAT IT REJECTS, AND WHY REJECTING IS THE POINT
 * ============================================================================
 * Anchors/aliases (`&x`, `*x`), tags (`!!str`), flow collections (`{`, `[`),
 * block scalars (`|`, `>`), explicit keys (`? `), complex mapping keys
 * (`? [a, b]`), sequences of mappings, tab indentation, inconsistent
 * indentation, and duplicate keys.
 *
 * Every one of those throws with a line number instead of being guessed at.
 * A release descriptor is a file upstream is free to rewrite; a reader that
 * silently mis-parses it would produce identity documents claiming a version
 * the ontology URLs contradict. Loud failure is the correct default.
 *
 * @package Civi\Dfc
 */
final class ReleaseDescriptorParser
{
    private function __construct()
    {
        // Static utility.
    }

    /**
     * Read and parse a descriptor from disk.
     *
     * @return array<string, mixed>
     *
     * @throws MalformedReleaseDescriptorException
     */
    public static function fromFile(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new MalformedReleaseDescriptorException(sprintf(
                'DFC release descriptor is not a readable file: %s',
                $path
            ));
        }

        $yaml = file_get_contents($path);
        if ($yaml === false) {
            throw new MalformedReleaseDescriptorException(sprintf(
                'Could not read DFC release descriptor: %s',
                $path
            ));
        }

        return self::parse($yaml);
    }

    /**
     * Parse a descriptor already in memory.
     *
     * @return array<string, mixed>
     *
     * @throws MalformedReleaseDescriptorException
     */
    public static function parse(string $yaml): array
    {
        $lines = self::tokenise($yaml);
        if ($lines === []) {
            throw new MalformedReleaseDescriptorException('The DFC release descriptor is empty.');
        }

        $index = 0;
        $parsed = self::parseBlock($lines, $index, $lines[0]['indent']);

        if ($index !== count($lines)) {
            throw new MalformedReleaseDescriptorException(sprintf(
                'Unexpected content at line %d (column %d): %s',
                $lines[$index]['line'],
                $lines[$index]['indent'] + 1,
                $lines[$index]['raw']
            ));
        }

        return $parsed;
    }

    /**
     * Strip comments and blank lines; measure indentation.
     *
     * @return list<array{indent: int, text: string, line: int, raw: string}>
     */
    private static function tokenise(string $yaml): array
    {
        $normalised = str_replace(["\r\n", "\r"], "\n", $yaml);
        $out = [];

        foreach (explode("\n", $normalised) as $offset => $raw) {
            $number = $offset + 1;

            $line = self::stripComment($raw);
            if (trim($line) === '') {
                continue;
            }

            // Measure the leading whitespace explicitly. `strlen - strlen(ltrim($line,' '))`
            // would report 0 for a tab-indented line, because ltrim() with a
            // space-only mask does not strip tabs — which would silently accept
            // tab indentation as column 0 instead of rejecting it.
            preg_match('/^[ \t]*/', $line, $ws);
            $whitespace = $ws[0];
            $leading = strlen($whitespace);

            if (str_contains($whitespace, "\t")) {
                throw new MalformedReleaseDescriptorException(sprintf(
                    'Tab used for indentation at line %d. YAML forbids tabs in indentation, and guessing the '
                    . 'intended depth of a release descriptor is not safe.',
                    $number
                ));
            }

            $out[] = [
                'indent' => $leading,
                'text' => rtrim(substr($line, $leading)),
                'line' => $number,
                'raw' => rtrim($raw),
            ];
        }

        return $out;
    }

    /**
     * Remove a trailing `# ...` comment, respecting quoting.
     *
     * A `#` only opens a comment when it is at the start of the content or
     * preceded by whitespace, and only outside quotes. That is the YAML rule and
     * it is what lets `prefix: "https://example.org/ns/#anchor"` survive.
     */
    private static function stripComment(string $line): string
    {
        $length = strlen($line);
        $quote = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];

            if ($quote !== null) {
                if ($char === '\\' && $quote === '"') {
                    $i++;
                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }

            if ($char === '#' && ($i === 0 || $line[$i - 1] === ' ')) {
                return substr($line, 0, $i);
            }
        }

        return $line;
    }

    /**
     * Parse every line at exactly $indent, as either a mapping or a sequence.
     *
     * @param list<array{indent: int, text: string, line: int, raw: string}> $lines
     *
     * @return array<string, mixed>|list<string>
     */
    private static function parseBlock(array $lines, int &$index, int $indent): array
    {
        $isSequence = self::isSequenceEntry($lines[$index]);
        $result = [];

        while ($index < count($lines)) {
            $line = $lines[$index];

            if ($line['indent'] < $indent) {
                break;
            }

            if ($line['indent'] > $indent) {
                throw new MalformedReleaseDescriptorException(sprintf(
                    'Unexpected indentation at line %d: expected %d space(s), found %d. The descriptor nests '
                    . 'block mappings by indentation; this line does not belong to the block above it.',
                    $line['line'],
                    $indent,
                    $line['indent']
                ));
            }

            if ($isSequence) {
                if (!self::isSequenceEntry($line)) {
                    throw new MalformedReleaseDescriptorException(sprintf(
                        'Line %d is not a sequence entry ("- ") but belongs to a block sequence. Mixing '
                        . 'sequence and mapping entries at the same level is not supported.',
                        $line['line']
                    ));
                }

                $result[] = self::readSequenceScalar($lines, $index);
                continue;
            }

            self::readMappingEntry($lines, $index, $indent, $result);
        }

        return $result;
    }

    /**
     * @param list<array{indent: int, text: string, line: int, raw: string}> $lines
     * @param array<string, mixed> $result
     */
    private static function readMappingEntry(array $lines, int &$index, int $indent, array &$result): void
    {
        $line = $lines[$index];
        $split = self::splitKey($line['text'], $line['line']);
        $key = $split['key'];
        $rest = $split['rest'];

        if (array_key_exists($key, $result)) {
            throw new MalformedReleaseDescriptorException(sprintf(
                'Duplicate key "%s" at line %d. A release descriptor with two values for one pin has no '
                . 'single source of truth, which is the entire reason this file exists.',
                $key,
                $line['line']
            ));
        }

        if ($rest !== null) {
            $result[$key] = self::readScalar($rest, $line['line']);
            $index++;

            return;
        }

        // A key with no inline value introduces a nested block, or is null when
        // the next line dedents.
        $next = $lines[$index + 1] ?? null;
        if ($next === null || $next['indent'] <= $indent) {
            $result[$key] = null;
            $index++;

            return;
        }

        $index++;
        $child = self::parseBlock($lines, $index, $next['indent']);
        $result[$key] = $child;
    }

    /**
     * @param list<array{indent: int, text: string, line: int, raw: string}> $lines
     */
    private static function readSequenceScalar(array $lines, int &$index): string
    {
        $line = $lines[$index];
        $item = ltrim(substr($line['text'], 1));

        if ($item === '') {
            throw new MalformedReleaseDescriptorException(sprintf(
                'Empty sequence entry at line %d. Nested collections inside a sequence are not supported.',
                $line['line']
            ));
        }

        // Reject `- key: value` rather than reading it as the string "key: value":
        // a mapping smuggled in as a scalar is exactly the silent misparse this
        // reader exists to prevent.
        if (self::isMappingEntry($item)) {
            throw new MalformedReleaseDescriptorException(sprintf(
                'Line %d is a mapping inside a sequence ("- key: value"). Sequences of scalars are supported; '
                . 'sequences of mappings are not.',
                $line['line']
            ));
        }

        $index++;

        return self::readScalar($item, $line['line']);
    }

    /**
     * @return array{key: string, rest: string|null}
     */
    private static function splitKey(string $text, int $lineNumber): array
    {
        if ($text === '' || $text[0] === '?') {
            throw new MalformedReleaseDescriptorException(sprintf(
                'Line %d is not a "key: value" mapping entry: %s',
                $lineNumber,
                $text
            ));
        }

        $length = strlen($text);
        $key = '';

        if ($text[0] === '"' || $text[0] === "'") {
            $quote = $text[0];
            $end = self::findQuoteEnd($text, $quote, $lineNumber);
            $key = self::unquote(substr($text, 0, $end + 1), $lineNumber);
            $rest = substr($text, $end + 1);
            $rest = ltrim($rest);
            if (!str_starts_with($rest, ':')) {
                throw new MalformedReleaseDescriptorException(sprintf(
                    'Expected ":" after the quoted key on line %d, got %s',
                    $lineNumber,
                    $text
                ));
            }

            return ['key' => $key, 'rest' => self::trimOrNull(substr($rest, 1))];
        }

        for ($i = 0; $i < $length; $i++) {
            if ($text[$i] !== ':') {
                $key .= $text[$i];
                continue;
            }

            // A ':' only terminates a plain key when followed by whitespace or
            // end of line, so "https://host" inside a value is not a key split.
            $next = $text[$i + 1] ?? ' ';
            if ($next !== ' ' && $next !== "\t") {
                $key .= $text[$i];
                continue;
            }

            $key = rtrim($key);

            return ['key' => $key, 'rest' => self::trimOrNull(substr($text, $i + 1))];
        }

        throw new MalformedReleaseDescriptorException(sprintf(
            'Line %d is not a "key: value" mapping entry (no ":" found): %s',
            $lineNumber,
            $text
        ));
    }

    private static function isMappingEntry(string $text): bool
    {
        try {
            self::splitKey($text, 0);

            return true;
        } catch (MalformedReleaseDescriptorException) {
            return false;
        }
    }

    /**
     * @throws MalformedReleaseDescriptorException
     */
    private static function readScalar(string $raw, int $lineNumber): string
    {
        // $raw is never empty by the time it gets here: readMappingEntry() only
        // calls this for a non-empty inline value, and readSequenceScalar() has
        // its own dedicated "Empty sequence entry" error. In YAML `a:` and
        // `a: ""` are both legitimately empty-ish, so there is no error case
        // left to detect — see trimOrNull().
        $value = trim($raw);

        $first = $value[0];
        if ($first === '&' || $first === '*') {
            throw new MalformedReleaseDescriptorException(sprintf(
                'YAML anchors and aliases are not supported (line %d): %s',
                $lineNumber,
                $value
            ));
        }
        if ($first === '!') {
            throw new MalformedReleaseDescriptorException(sprintf(
                'YAML tags are not supported (line %d): %s',
                $lineNumber,
                $value
            ));
        }
        if ($first === '{' || $first === '[') {
            throw new MalformedReleaseDescriptorException(sprintf(
                'Flow collections are not supported (line %d): %s',
                $lineNumber,
                $value
            ));
        }
        if ($value === '|' || $value === '>' || str_starts_with($value, '|-') || str_starts_with($value, '>-')) {
            throw new MalformedReleaseDescriptorException(sprintf(
                'Block scalars are not supported (line %d): %s',
                $lineNumber,
                $value
            ));
        }

        if ($first === '"' || $first === "'") {
            return self::unquote($value, $lineNumber);
        }

        return $value;
    }

    private static function trimOrNull(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function isSequenceEntry(array $line): bool
    {
        return $line['text'] === '-' || str_starts_with($line['text'], '- ');
    }

    /**
     * @throws MalformedReleaseDescriptorException
     */
    private static function findQuoteEnd(string $text, string $quote, int $lineNumber): int
    {
        $length = strlen($text);

        for ($i = 1; $i < $length; $i++) {
            if ($quote === '"' && $text[$i] === '\\') {
                $i++;
                continue;
            }
            if ($text[$i] === $quote) {
                if ($quote === "'" && ($text[$i + 1] ?? '') === "'") {
                    $i++;
                    continue;
                }

                return $i;
            }
        }

        throw new MalformedReleaseDescriptorException(sprintf(
            'Unterminated %s-quoted scalar on line %d: %s',
            $quote === '"' ? 'double' : 'single',
            $lineNumber,
            $text
        ));
    }

    /**
     * @throws MalformedReleaseDescriptorException
     */
    private static function unquote(string $value, int $lineNumber): string
    {
        $quote = $value[0];
        $end = self::findQuoteEnd($value, $quote, $lineNumber);

        if (rtrim(substr($value, $end + 1)) !== '') {
            throw new MalformedReleaseDescriptorException(sprintf(
                'Trailing content after a quoted scalar on line %d: %s',
                $lineNumber,
                $value
            ));
        }

        $inner = substr($value, 1, $end - 1);

        if ($quote === "'") {
            return str_replace("''", "'", $inner);
        }

        return self::unescapeDoubleQuoted($inner, $lineNumber);
    }

    private static function unescapeDoubleQuoted(string $inner, int $lineNumber): string
    {
        return (string) preg_replace_callback(
            '/\\\\(?:u[0-9A-Fa-f]{4}|.)/s',
            /**
             * @param array<int, string> $m
             */
            static function (array $m) use ($lineNumber): string {
                $escape = substr($m[0], 1);

                if ($escape[0] === 'u') {
                    return (string) mb_chr((int) hexdec(substr($escape, 1)), 'UTF-8');
                }

                return match ($escape) {
                    'n' => "\n",
                    't' => "\t",
                    'r' => "\r",
                    '0' => "\0",
                    '\\' => '\\',
                    '"' => '"',
                    '/' => '/',
                    default => throw new MalformedReleaseDescriptorException(sprintf(
                        'Unsupported escape sequence "\\%s" in a double-quoted scalar on line %d. '
                        . 'Supported: \\n \\t \\r \\0 \\\\ \\" \\/ \\uXXXX',
                        $escape,
                        $lineNumber
                    )),
                };
            },
            $inner
        );
    }
}