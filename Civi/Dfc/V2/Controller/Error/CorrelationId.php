<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Controller\Error;

/**
 * A validated correlation id: the one value in an error body that comes from
 * outside the fixed vocabulary.
 *
 * PRD-002 §9 requires "correlation IDs + structured error codes instead" of
 * logging tokens and personal data, and §4.15 requires structured audit. An
 * error a user can quote is worth having, but only if quoting it cannot carry
 * arbitrary text into a response or a log — so the accepted shapes are exactly
 * two, both fixed-length and fixed-alphabet:
 *
 *   - ULID, 26 characters of Crockford base32 (what {@see \Civi\Dfc\V2\Identity\}
 *     identifiers already look like, so one id vocabulary serves the whole
 *     extension);
 *   - RFC 4122 UUID, hyphenated, any case.
 *
 * Anything else is rejected rather than sanitised. A correlation id is
 * generated, never parsed out of user input, so rejecting is cheap and never
 * happens on a real path.
 *
 * @package Civi\Dfc
 */
final class CorrelationId
{
    private const ULID_PATTERN = '/^[0-9A-HJKMNP-TV-Z]{26}$/';

    private const UUID_PATTERN = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';

    private readonly string $value;

    private function __construct(string $value)
    {
        $this->value = $value;
    }

    public static function tryFromString(string $value): ?self
    {
        $candidate = trim($value);

        if (preg_match(self::ULID_PATTERN, $candidate) !== 1
            && preg_match(self::UUID_PATTERN, $candidate) !== 1
        ) {
            return null;
        }

        return new self($candidate);
    }

    /**
     * @throws \InvalidArgumentException when $value is neither a ULID nor a UUID.
     */
    public static function fromString(string $value): self
    {
        $correlationId = self::tryFromString($value);

        if ($correlationId === null) {
            throw new \InvalidArgumentException(
                'A correlation id must be a 26-character ULID or a hyphenated UUID. It is a generated '
                . 'identifier, so anything else is a defect in the caller rather than bad input.'
            );
        }

        return $correlationId;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
