<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Validation;

use Civi\Dfc\V2\Identity\DfcReleaseConfig;
use Civi\Dfc\V2\Security\AuthenticatedPrincipal;

/**
 * Everything a stage is allowed to see about one request, plus the one flag that
 * gates mutation.
 *
 * ============================================================================
 * IMMUTABLE, AND EVERY DERIVATION IS EXPLICIT
 * ============================================================================
 * Each stage receives the context the previous one produced via a `with*()` method
 * returning a NEW instance. Two reasons, and the second is the important one:
 *
 *   1. A stage cannot quietly change what a later stage sees.
 *   2. The audit record can be built from the chain of contexts rather than from a
 *      mutable object whose history nobody kept.
 *
 * ============================================================================
 * `validated` IS THE ENFORCEMENT OF "BEFORE ANY MUTATION"
 * ============================================================================
 * The flag starts false and is set ONLY by {@see ValidationPipeline}, and only once
 * every registered pre-mutation stage has passed. {@see assertMayMutate()} then
 * refuses otherwise.
 *
 * So the guarantee is not "the pipeline runs in order, and we hope the writer
 * cooperates". A mutation stage that reaches for the context without the flag gets a
 * {@see MutationNotAuthorised} — a hard stop, in the only place that can enforce it.
 * That is the difference between an ordering convention and a control.
 *
 * The flag is public because {@see ValidationPipeline} is a different class; there is
 * no setter, it is a constructor argument, and the docblock on it is the warning.
 *
 * ============================================================================
 * A GET CARRIES NO GRAPH, AND THAT IS NOT AN ERROR
 * ============================================================================
 * `requiresGraph()` is false for a request with no body. The inbound pipeline then
 * skips the document-shaped stages — SHACL over a document that does not exist has
 * nothing to say, and outbound validation of a STORED representation belongs to the
 * export path (lane-3/4), not here. What is NOT allowed is a POST with no graph:
 * {@see requireGraph()} returns the failure, so a mutating request cannot skip
 * validation by omitting its body.
 *
 * @package Civi\Dfc
 *
 * @see ValidationPipeline — the only caller of markValidated()
 */
final class ValidationContext
{
    private readonly string $method;

    private readonly string $requestUri;

    private readonly ?string $rawBody;

    /** @var array<string, mixed>|null */
    private readonly ?array $graph;

    private readonly ?string $subject;

    private readonly ?AuthenticatedPrincipal $principal;

    private readonly DfcReleaseConfig $release;

    private readonly bool $requiresGraph;

    private readonly bool $validated;

    /**
     * @param array<string, mixed>|null $graph
     */
    public function __construct(
        DfcReleaseConfig $release,
        string $method,
        string $requestUri,
        ?string $rawBody = null,
        ?array $graph = null,
        ?string $subject = null,
        ?AuthenticatedPrincipal $principal = null,
        bool $requiresGraph = false,
        bool $validated = false
    ) {
        $this->release = $release;
        $this->method = self::assertMethod($method);
        $this->requestUri = self::assertRequestUri($requestUri);
        $this->rawBody = $rawBody;
        $this->graph = $graph;
        $this->subject = $subject === null ? null : self::assertSubject($subject);
        $this->principal = $principal;
        $this->requiresGraph = $requiresGraph;
        $this->validated = $validated;
    }

    /**
     * A body-less request (GET / HEAD / OPTIONS).
     */
    public static function read(DfcReleaseConfig $release, string $method, string $requestUri): self
    {
        return new self($release, $method, $requestUri);
    }

    /**
     * A request that carries a document, and therefore cannot skip validation.
     */
    public static function write(
        DfcReleaseConfig $release,
        string $method,
        string $requestUri,
        ?string $rawBody
    ): self {
        return new self($release, $method, $requestUri, $rawBody, null, null, null, true);
    }

    // -- Accessors ------------------------------------------------------------

    public function release(): DfcReleaseConfig
    {
        return $this->release;
    }

    /** Upper-cased HTTP method. */
    public function method(): string
    {
        return $this->method;
    }

    /** The absolute https URI this request was made to. */
    public function requestUri(): string
    {
        return $this->requestUri;
    }

    public function rawBody(): ?string
    {
        return $this->rawBody;
    }

    /**
     * The decoded document, once the parse stage has produced one.
     *
     * @return array<string, mixed>|null
     */
    public function graph(): ?array
    {
        return $this->graph;
    }

    /** The subject IRI the request is about, when the route knows it. */
    public function subject(): ?string
    {
        return $this->subject;
    }

    public function principal(): ?AuthenticatedPrincipal
    {
        return $this->principal;
    }

    /**
     * Must this request carry a document?
     *
     * True for POST/PUT/PATCH by construction ({@see write()}); a mutating request
     * that omits its body is a client bug the parse stage reports as a 400, not a
     * validation stage that silently has nothing to do.
     */
    public function requiresGraph(): bool
    {
        return $this->requiresGraph;
    }

    public function hasGraph(): bool
    {
        return $this->graph !== null;
    }

    /**
     * Has every registered pre-mutation stage passed?
     *
     * Set by {@see ValidationPipeline} alone. See the class docblock.
     */
    public function isValidated(): bool
    {
        return $this->validated;
    }

    /**
     * Refuse to proceed unless the pipeline said every check passed.
     *
     * @throws MutationNotAuthorised
     */
    public function assertMayMutate(): void
    {
        if (!$this->validated) {
            throw new MutationNotAuthorised(sprintf(
                'A mutation was attempted for %s %s before the validation pipeline had passed. Run '
                . 'ValidationPipeline::run() first; it is the only thing that may mark a context validated.',
                $this->method,
                $this->requestUri
            ));
        }
    }

    // -- Derivations ----------------------------------------------------------

    /**
     * @param array<string, mixed> $graph
     */
    public function withGraph(array $graph): self
    {
        return new self(
            $this->release,
            $this->method,
            $this->requestUri,
            $this->rawBody,
            $graph,
            $this->subject,
            $this->principal,
            $this->requiresGraph,
            $this->validated
        );
    }

    public function withSubject(?string $subject): self
    {
        return new self(
            $this->release,
            $this->method,
            $this->requestUri,
            $this->rawBody,
            $this->graph,
            $subject,
            $this->principal,
            $this->requiresGraph,
            $this->validated
        );
    }

    public function withPrincipal(?AuthenticatedPrincipal $principal): self
    {
        return new self(
            $this->release,
            $this->method,
            $this->requestUri,
            $this->rawBody,
            $this->graph,
            $this->subject,
            $principal,
            $this->requiresGraph,
            $this->validated
        );
    }

    public function withRawBody(?string $rawBody): self
    {
        return new self(
            $this->release,
            $this->method,
            $this->requestUri,
            $rawBody,
            $this->graph,
            $this->subject,
            $this->principal,
            $this->requiresGraph,
            $this->validated
        );
    }

    /**
     * Mark as validated.
     *
     * PUBLIC, and called by exactly one class. The alternative — making
     * {@see ValidationPipeline} a friend — is not available in PHP, and an
     * `assertMayMutate()` that consults the pipeline instead of a flag would make
     * the pipeline mutable state that any request could observe.
     *
     * The public API surface is therefore one method with a docblock that says who
     * calls it and what happens otherwise, on an object that can do nothing else
     * dangerous. That is the smallest hole available in PHP, and it is closed by
     * {@see assertMayMutate()} raising rather than by making the method private.
     */
    public function markValidated(): self
    {
        return new self(
            $this->release,
            $this->method,
            $this->requestUri,
            $this->rawBody,
            $this->graph,
            $this->subject,
            $this->principal,
            $this->requiresGraph,
            true
        );
    }

    /**
     * The graph, or a failure explaining that this request has none.
     *
     * @throws NoDocumentToValidate
     */
    public function requireGraph(): array
    {
        if ($this->graph === null) {
            throw new NoDocumentToValidate(
                $this->requiresGraph
                    ? 'A mutating request reached a validation stage with no decoded document, so there is '
                        . 'nothing to validate. The body was empty or the parse stage did not run.'
                    : 'A validation stage that needs a document was reached on a request that carries none.'
            );
        }

        return $this->graph;
    }

    /**
     * The authenticated principal, or a failure explaining there is none.
     *
     * @throws NoAuthenticatedPrincipal
     */
    public function requirePrincipal(): AuthenticatedPrincipal
    {
        if ($this->principal === null) {
            throw new NoAuthenticatedPrincipal(
                'A stage that needs an authenticated identity was reached before the authentication stage, or '
                . 'on an anonymous request. Check the stage order.'
            );
        }

        return $this->principal;
    }

    /**
     * Audit record. No request body, no token, no personal data.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'requestUri' => $this->requestUri,
            'subject' => $this->subject,
            'requiresGraph' => $this->requiresGraph,
            'hasGraph' => $this->hasGraph(),
            'authenticated' => $this->principal !== null,
            'validated' => $this->validated,
        ];
    }

    // -- Validation -----------------------------------------------------------

    private static function assertMethod(string $method): string
    {
        $upper = strtoupper(trim($method));
        if (preg_match('/^[A-Z]{1,20}$/', $upper) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'An HTTP method must be one or more ASCII letters. Got "%s".',
                $method
            ));
        }

        return $upper;
    }

    private static function assertRequestUri(string $uri): string
    {
        if (preg_match('#^https://[^\s/?\#]+(?::\d+)?(?:/[^\s?\#]*)?$#', $uri) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'A validation context needs the absolute https URI the request was made to. Got "%s". An '
                . 'HTTP-on-loopback base is permitted by DfcReleaseConfig for tests, but the protocol layer '
                . 'itself refuses plaintext.',
                $uri
            ));
        }

        return $uri;
    }

    private static function assertSubject(string $subject): string
    {
        if (preg_match('#^https://[^\s/?\#]+(?::\d+)?/[^\s?\#]*$#', $subject) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'A DFC subject must be an absolute https IRI with no query string. Got "%s".',
                $subject
            ));
        }

        return $subject;
    }
}