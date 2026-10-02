<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Controller\Http;

/**
 * An immutable, ordered HTTP header multimap.
 *
 * ============================================================================
 * WHY THIS IS A VALUE OBJECT AND NOT AN ARRAY
 * ============================================================================
 * Two things are wrong with `['Link' => [...], 'Allow' => 'GET']` in a protocol
 * layer:
 *
 *   1. CASE. Header names are case-insensitive, so `$headers['allow']` silently
 *      misses `$headers['Allow']` and a capability quietly disappears from an
 *      OPTIONS response. Lookups here are case-insensitive; the case a caller
 *      wrote is preserved only for output.
 *   2. INJECTION. A value containing CR or LF lets anything that later writes
 *      this map into a response header forge extra headers. That is a real
 *      vulnerability class (response splitting), and the only reliable defence is
 *      refusing the value at construction. Names are validated against the RFC 9110
 *      `token` grammar and values against a no-control-character rule.
 *
 * `with()` returns a new instance, so a policy that has computed `Allow` cannot
 * be mutated by a caller that kept a reference — the mistake that turns "all
 * headers are validated" into "all headers were validated once".
 *
 * @package Civi\Dfc
 */
final class ResponseHeaders implements \Countable, \IteratorAggregate
{
    /** RFC 9110 §5.6.2 `token`. */
    private const NAME_PATTERN = '/^[A-Za-z0-9!#$%&\'*+.^_`|~\-]+$/';

    private const MAX_NAME_BYTES = 128;

    /** Generous next to any real header; a bound is what makes this a check. */
    private const MAX_VALUE_BYTES = 8192;

    private const MAX_HEADERS = 32;

    /** @var array<string, string> Upper-case key => the name as first written. */
    private array $names = [];

    /** @var array<string, list<string>> Upper-case key => values, in order. */
    private array $values = [];

    private function __construct()
    {
    }

    public static function create(): self
    {
        return new self();
    }

    /** Append one or more values for a header, keeping any existing ones. */
    public function with(string $name, string ...$values): self
    {
        $clone = clone $this;
        $key = self::lookupKey($name);

        if (!array_key_exists($key, $clone->values)) {
            if (count($clone->values) >= self::MAX_HEADERS) {
                throw new \InvalidArgumentException(sprintf(
                    'A response may carry at most %d distinct header fields. Adding "%s" exceeds it, which '
                    . 'means a policy is looping rather than describing one response.',
                    self::MAX_HEADERS,
                    $name
                ));
            }

            $clone->names[$key] = $name;
            $clone->values[$key] = [];
        }

        foreach ($values as $value) {
            $clone->values[$key][] = self::validateValue($name, $value);
        }

        return $clone;
    }

    /** Replace any existing values for a header with exactly these. */
    public function withOnly(string $name, string ...$values): self
    {
        return $this->remove($name)->with($name, ...$values);
    }

    /** @return list<string> */
    public function get(string $name): array
    {
        return $this->values[self::lookupKey($name)] ?? [];
    }

    public function first(string $name): ?string
    {
        return $this->get($name)[0] ?? null;
    }

    public function has(string $name): bool
    {
        return array_key_exists(self::lookupKey($name), $this->values);
    }

    public function remove(string $name): self
    {
        $clone = clone $this;
        $key = self::lookupKey($name);
        unset($clone->names[$key], $clone->values[$key]);

        return $clone;
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    /**
     * Insertion-ordered `name => list<value>`, with the case each name was first
     * written in.
     *
     * The shape a route hands to whichever PSR-7 / CMS response object it has.
     *
     * @return array<string, list<string>>
     */
    public function toArray(): array
    {
        $fields = [];

        foreach ($this->values as $key => $values) {
            $fields[$this->names[$key]] = $values;
        }

        return $fields;
    }

    /** One `Name: value` line per field value, for logging and tests. */
    public function render(): array
    {
        $lines = [];

        foreach ($this->values as $key => $values) {
            foreach ($values as $value) {
                $lines[] = $this->names[$key] . ': ' . $value;
            }
        }

        return $lines;
    }

    public function count(): int
    {
        return count($this->values);
    }

    /**
     * @return \Traversable<string, list<string>>
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->toArray());
    }

    private static function lookupKey(string $name): string
    {
        self::validateName($name);

        return strtoupper($name);
    }

    private static function validateName(string $name): string
    {
        if ($name === '' || strlen($name) > self::MAX_NAME_BYTES) {
            throw new \InvalidArgumentException(
                'An HTTP header name must be non-empty and at most 128 bytes.'
            );
        }

        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                '"%s" is not a valid HTTP header name. Names must be RFC 9110 tokens: letters, digits and '
                . '"!#$%%&\'*+-.^_`|~" only. Anything else is either a typo or an injection attempt.',
                $name
            ));
        }

        return $name;
    }

    private static function validateValue(string $name, string $value): string
    {
        if (strlen($value) > self::MAX_VALUE_BYTES) {
            throw new \InvalidArgumentException(sprintf(
                'The value of "%s" is %d bytes, over the %d-byte limit for one header value.',
                $name,
                strlen($value),
                self::MAX_VALUE_BYTES
            ));
        }

        // The whole defence against response splitting. An empty value is allowed:
        // `X-Empty:` is legal HTTP and some proxies care about it.
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new \InvalidArgumentException(sprintf(
                'The value of "%s" contains a control character. CR and LF in a header value let a caller '
                . 'forge additional response headers, so they are refused here rather than escaped later.',
                $name
            ));
        }

        return $value;
    }
}
