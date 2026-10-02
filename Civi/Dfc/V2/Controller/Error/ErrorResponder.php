<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Controller\Error;

use Civi\Dfc\V2\Controller\Http\ProtocolResponse;
use Civi\Dfc\V2\Controller\Http\ResponseHeaders;
use Civi\Dfc\V2\Controller\Serialisation\CanonicalJson;

/**
 * Turns any throwable into a complete, sendable error response.
 *
 * ============================================================================
 * THIS IS WHAT MAKES THE ROUTES THIN
 * ============================================================================
 * PRD-002 §2 describes the desired shape of this layer: "protocol abstractions
 * testable without a CMS bootstrap", with routes that "delegate all behaviour to
 * services". A route therefore needs exactly one statement — hand me whatever
 * happened — and this is that statement. Every route ends with
 *
 *     return $this->responder->response($e);
 *
 * and cannot decide a status, a media type or a header.
 *
 * ============================================================================
 * ERROR BODIES ARE `application/problem+json`, NOT `application/ld+json`
 * ============================================================================
 * A deliberate, and defensible, departure from "everything this server emits is
 * JSON-LD":
 *
 *   - a problem document describes a FAILURE. Its `@context` would be a DFC
 *     context describing a DFC resource that does not exist, and a client that
 *     expands the error against the DFC ontology would find no node for it;
 *   - RFC 9457's media type exists precisely so a client can recognise an error
 *     without parsing it, which is the whole purpose here;
 *   - making errors participate in content negotiation would mean a Turtle-only
 *     client gets an error in Turtle, which no DFC client can usefully read and
 *     which we would then have to implement a serialiser for.
 *
 * So the error surface has its own media type and does not enter negotiation. The
 * negotiator's offer lists describe DFC *representations* only, and a route that
 * fails before negotiation still produces a well-formed response.
 *
 * ============================================================================
 * `Cache-Control: no-store` ON EVERY ERROR
 * ============================================================================
 * A 401 or a 403 in a shared cache is a correctness bug before it is a security
 * bug: the second client to ask receives the FIRST client's authorisation
 * decision. A 500 is likewise not cacheable — retrying it later is often the
 * right thing. RFC 9111 allows either status without `no-store`, and this layer
 * takes the option that cannot be got wrong.
 *
 * @package Civi\Dfc
 */
final class ErrorResponder
{
    /** RFC 9457. */
    public const PROBLEM_CONTENT_TYPE = 'application/problem+json';

    private readonly ErrorMapper $mapper;

    private readonly ResponseHeaders $baseHeaders;

    /**
     * @param ResponseHeaders|null $baseHeaders Headers every error carries. The
     *        default emits `Cache-Control: no-store`; a deployment may add its
     *        own without touching this class.
     */
    public function __construct(ErrorMapper $mapper, ?ResponseHeaders $baseHeaders = null)
    {
        $this->mapper = $mapper;
        $this->baseHeaders = $baseHeaders ?? ResponseHeaders::create()
            ->with('Cache-Control', 'no-store');
    }

    public function mapper(): ErrorMapper
    {
        return $this->mapper;
    }

    /**
     * Render any throwable as a response. Cannot itself throw.
     */
    public function response(\Throwable $throwable): ProtocolResponse
    {
        return $this->forError($this->mapper->fromThrowable($throwable));
    }

    /**
     * Render an already-classified error.
     */
    public function forError(ProtocolError $error): ProtocolResponse
    {
        $headers = $this->baseHeaders->with('Content-Type', self::PROBLEM_CONTENT_TYPE);

        foreach ($error->headers() as $name => $value) {
            $headers = $headers->withOnly($name, $value);
        }

        return new ProtocolResponse($error->status(), $headers, $this->body($error));
    }

    /**
     * The exact response body bytes.
     *
     * Canonical for the same reason a resource representation is: identical
     * failures produce identical bytes, so a conformance test can compare them
     * literally and a cache cannot be fooled by cosmetic re-encoding.
     */
    public function body(ProtocolError $error): string
    {
        try {
            return $error->toJson();
        } catch (\Throwable $serialisationFailure) {
            // The problem document itself failed to build. Emit the smallest
            // possible valid problem document rather than propagating: a client
            // receiving a 500 with a correct shape is far better served than a
            // request that reaches the error handler and dies there.
            return CanonicalJson::encode([
                'type' => ErrorCode::INTERNAL_ERROR->problemType(),
                'code' => ErrorCode::INTERNAL_ERROR->value,
                'status' => 500,
                'title' => ErrorCode::INTERNAL_ERROR->title(),
                'detail' => ErrorCode::INTERNAL_ERROR->detail(),
                'redacted' => true,
            ]);
        }
    }
}
