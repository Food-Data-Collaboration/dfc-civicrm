<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Controller\Error;

/**
 * The location of a violation inside a submitted DFC document.
 *
 * ============================================================================
 * THE DESIGN CONSTRAINT THAT SHAPED THIS CLASS: NO STRING CONSTRUCTOR
 * ============================================================================
 * A validation path has to be echoed back to the client, and echoing is only
 * safe if what you echo is *derived from the submitted document* rather than
 * assembled from server state. So there is no `DfcPath::fromString()` and no
 * `DfcPath::fromPointer()`. The only public entry points take a **list of
 * tokens**, which a caller can only obtain by walking the structure it is
 * validating:
 *
 *     DfcPath::fromTokens('organizations', '0', 'name')
 *
 * The consequence is that the classic leak — `sprintf('%s:%d', $e->getFile(),
 * $e->getLine())` landing in a diagnostic path — has no API to land in. Building
 * a path still requires *deliberate* effort, and a deliberate effort is a
 * different threat model from an accident.
 *
 * The residual is stated plainly rather than papered over: a developer who
 * explodes a server string into valid-looking tokens can still get it in. That
 * is why {@see ProtocolError} additionally runs {@see LeakGuard} over its own
 * output. See sa-005's report for what the guarantee does and does not cover.
 *
 * ============================================================================
 * TOKEN RULES
 * ============================================================================
 * A token is either an XML-ish NCName (`[A-Za-z0-9_][A-Za-z0-9_.-]*`, max 128
 * bytes) or a CURIE (`prefix:local`, both halves NCName-like). That is enough for
 * `organizations`, `0`, `index`, `dfc-b:name` and `name` — everything a DFC
 * document nests under — and it excludes anything containing whitespace,
 * a separator, a control character, or a drive letter, because `C:` fails the
 * CURIE half and `C:/Users` never reaches this class as a token.
 *
 * Rendering joins tokens with `/` and prefixes `/`; the empty path renders as
 * `/`, meaning "the submitted document as a whole".
 *
 * @package Civi\Dfc
 */
final class DfcPath
{
    private const MAX_TOKENS = 64;

    private const MAX_TOKEN_BYTES = 128;

    private const NAME_PATTERN = '/^[A-Za-z0-9_][A-Za-z0-9_.\-]{0,127}$/';

    private const CURIE_PATTERN = '/^[A-Za-z][A-Za-z0-9.\-]{0,63}:[A-Za-z0-9_][A-Za-z0-9_.\-]{0,127}$/';

    /** @var list<string> */
    private readonly array $tokens;

    /**
     * @param list<string> $tokens Already-validated tokens; see the class docblock.
     */
    private function __construct(array $tokens)
    {
        $this->tokens = $tokens;
    }

    /** The whole document. Renders as `/`. */
    public static function root(): self
    {
        return new self([]);
    }

    public static function fromTokens(string ...$tokens): self
    {
        return new self(self::validateAll($tokens));
    }

    /**
     * @param list<string> $tokens
     */
    public static function fromList(array $tokens): self
    {
        foreach ($tokens as $token) {
            if (!is_string($token)) {
                throw new \InvalidArgumentException(sprintf(
                    'A DFC path token must be a string. Got %s. Tokens come from walking the submitted '
                    . 'document, so a non-string means the caller is building the path some other way.',
                    get_debug_type($token)
                ));
            }
        }

        /** @var list<string> $tokens */
        return new self(self::validateAll($tokens));
    }

    /** A longer path. Immutable: `$path->append(...)` never mutates `$path`. */
    public function append(string ...$tokens): self
    {
        return new self(self::validateAll([...$this->tokens, ...$tokens]));
    }

    /** @return list<string> */
    public function tokens(): array
    {
        return $this->tokens;
    }

    public function isRoot(): bool
    {
        return $this->tokens === [];
    }

    public function depth(): int
    {
        return count($this->tokens);
    }

    /**
     * The wire form: `/a/b`, or `/` for the whole document.
     *
     * A leading `/` and no trailing `/`, so it composes with the JSON-Pointer
     * tooling most DFC clients already ship without being mistaken for a URI.
     */
    public function render(): string
    {
        return $this->tokens === [] ? '/' : '/' . implode('/', $this->tokens);
    }

    public function equals(self $other): bool
    {
        return $this->tokens === $other->tokens;
    }

    public function __toString(): string
    {
        return $this->render();
    }

    /**
     * @param list<string> $tokens
     *
     * @return list<string>
     */
    private static function validateAll(array $tokens): array
    {
        if (count($tokens) > self::MAX_TOKENS) {
            throw new \InvalidArgumentException(sprintf(
                'A DFC path may have at most %d tokens, got %d. A deeper path means the document nests '
                . 'deeper than any DFC class does, which is a malformed document rather than a deep one.',
                self::MAX_TOKENS,
                count($tokens)
            ));
        }

        foreach ($tokens as $position => $token) {
            self::validateToken($token, $position);
        }

        return array_values($tokens);
    }

    private static function validateToken(string $token, int $position): void
    {
        if ($token === '') {
            throw new \InvalidArgumentException(sprintf(
                'DFC path token #%d is empty. An empty token would render as a doubled separator and make the '
                . 'path ambiguous.',
                $position + 1
            ));
        }

        if (strlen($token) > self::MAX_TOKEN_BYTES) {
            throw new \InvalidArgumentException(sprintf(
                'DFC path token #%d is %d bytes, over the %d-byte limit. Path tokens are keys from the '
                . 'submitted document; an over-long one is a blob that was passed by mistake.',
                $position + 1,
                strlen($token),
                self::MAX_TOKEN_BYTES
            ));
        }

        if (preg_match(self::NAME_PATTERN, $token) === 1) {
            return;
        }

        if (preg_match(self::CURIE_PATTERN, $token) === 1) {
            return;
        }

        throw new \InvalidArgumentException(sprintf(
            'DFC path token #%d is not a usable token. A token is either an XML-like name ([A-Za-z0-9_]'
            . '[A-Za-z0-9_.-]*) or a CURIE (prefix:local), which excludes whitespace, path separators, '
            . 'control characters and anything that could carry server state.',
            $position + 1
        ));
    }
}
