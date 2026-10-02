<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Security;

use Civi\Dfc\V2\Controller\Error\DfcApiException;
use Civi\Dfc\V2\Controller\Error\DfcPath;
use Civi\Dfc\V2\Controller\Error\ProtocolError;
use Civi\Dfc\V2\Controller\Error\ValidationDiagnostic;
use Civi\Dfc\V2\Controller\Error\ValidationIssue;
use Civi\Dfc\V2\Controller\Http\ResponseHeaders;
use Civi\Dfc\V2\Identity\DfcReleaseConfig;

/**
 * The `x-dfc-version` required request header.
 *
 * ============================================================================
 * PROVENANCE — A REQUIREMENT THE PRD ITSELF MISSED
 * ============================================================================
 * PRD-002 "Upstream refresh" item 3:
 *
 *     The published contract requires an `x-dfc-version` header on every
 *     operation (DFC 2.0.0). The manifest's `surfaces` block and the protocol
 *     layer do not model it. It must be validated and echoed.
 *
 * `api-coverage-manifest.yaml` `surfaces.dfc_version_header` is the same rule: a
 * header named `x-dfc-version`, required on every operation, validated against the
 * configured release, 422 on disagreement, and "version is never baked into a minted
 * URI — it travels in this header and in @context" (which agrees with
 * {@see DfcReleaseConfig}'s rule R1).
 *
 * ============================================================================
 * WHY MISSING AND MISMATCHED ARE BOTH 422 — A DOCUMENTED CHOICE
 * ============================================================================
 * The manifest names 422 only for "disagrees". For ABSENT it is silent, and there
 * is no canon to defer to: RFC 9110 does not say, and RFC 6585's 428 is about
 * conditional headers (`If-Match`) rather than arbitrary required ones. This class
 * answers 422 for both, and the reason is that the two cases have the SAME remedy:
 * send the header with the value this deployment serves. A client that gets 422 for
 * a missing header and 400 for a wrong one has to implement two error paths for one
 * mistake; a client that gets 422 for both implements one. And {@see ErrorCode}'s
 * `VALIDATION_FAILED` detail — "valid JSON-LD but does not describe a valid DFC
 * resource" — is closer to the truth than a 400, whose detail says the request
 * "could not be interpreted" when in fact it parsed fine and simply asked for a
 * different DFC release.
 *
 * The refusal case this is NOT: nothing here accepts a version this deployment does
 * not serve, so a client cannot talk this interface into acting as a 2.0.1 server
 * by asking nicely. DfcReleaseConfig R1 keeps the version out of every URI, so
 * there is no second channel for a mismatch to arrive through.
 *
 * The diagnostic body is machine-readable rather than prose: a
 * {@see ValidationDiagnostic} whose path and predicate are both `x-dfc-version` and
 * whose `constraint` carries `equals=<configured>` or `required`. That is the same
 * shape every other validation failure on this surface uses, so a client parses one
 * thing.
 *
 * ============================================================================
 * ECHOING
 * ============================================================================
 * {@see echoOn()} adds the header to a response's
 * {@see \Civi\Dfc\V2\Controller\Http\ResponseHeaders}. It is a separate method
 * rather than a side effect of {@see validate()} because response headers belong to
 * {@see \Civi\Dfc\V2\Controller\Http\HeaderPolicy} and the error layer, neither of
 * which this class may modify — so the echo is a one-line call a route makes, and
 * the docblock says so.
 *
 * @package Civi\Dfc
 *
 * @see \Civi\Dfc\V2\Identity\DfcReleaseConfig — where the expected version comes from
 */
final class DfcVersionHeader
{
    /** The published contract's header name, lower-cased as HTTP header names are. */
    public const NAME = 'x-dfc-version';

    private readonly string $requested;

    private readonly string $configured;

    private function __construct(string $requested, string $configured)
    {
        $this->requested = $requested;
        $this->configured = $configured;
    }

    /**
     * Validate the header against the configured release.
     *
     * @param string|null    $rawHeader  The raw field value, or null when absent.
     * @param DfcReleaseConfig $config   The deployment's release contract.
     *
     * @throws DfcApiException 422 when the header is absent, unparseable, or names a
     *                          release this deployment does not serve.
     */
    public static function validate(?string $rawHeader, DfcReleaseConfig $config): self
    {
        $configured = $config->dfcVersion();

        if ($rawHeader === null || trim($rawHeader) === '') {
            throw self::failure(ValidationIssue::REQUIRED, 'required');
        }

        $requested = trim($rawHeader);

        // A list of versions is ambiguous, and picking one of them would be a guess
        // about which release the client meant. RFC 9110's list syntax means this
        // really does arrive in the wild.
        if (preg_match('/\s/', $requested) === 1 || str_contains($requested, ',')) {
            throw self::failure(ValidationIssue::MALFORMED, 'single-token');
        }

        if ($requested !== $configured) {
            throw self::failure(
                ValidationIssue::NOT_A_MEMBER,
                sprintf('equals=%s', $configured)
            );
        }

        return new self($requested, $configured);
    }

    public function name(): string
    {
        return self::NAME;
    }

    /** The value the client sent, which a successful validation proved to be {@see value()}. */
    public function requestedValue(): string
    {
        return $this->requested;
    }

    /**
     * The value a response should echo: the release this deployment serves.
     *
     * Identical to {@see requestedValue()} — that is the point of validating. Kept
     * separate so a route never echoes a client-supplied string.
     */
    public function value(): string
    {
        return $this->configured;
    }

    /**
     * The same release, for a response that did not go through {@see validate()}.
     *
     * Error responses and unauthenticated discovery still advertise what this
     * deployment speaks, so the header is never absent from a reply.
     */
    public static function configuredValue(DfcReleaseConfig $config): string
    {
        return $config->dfcVersion();
    }

    /**
     * Add the echo header to a response's header set.
     */
    public function echoOn(ResponseHeaders $headers): ResponseHeaders
    {
        return $headers->with(self::NAME, $this->configured);
    }

    /**
     * Audit record. No client-supplied text beyond the version string itself.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'name' => self::NAME,
            'requested' => $this->requested,
            'configured' => $this->configured,
        ];
    }

    /**
     * The one 422 this class produces.
     *
     * The path and the predicate are both the header name: it is a legal
     * {@see DfcPath} token (an NCName with dashes) and a legal diagnostic predicate,
     * so the no-leak guarantees in the error layer apply unchanged and the body is
     * built from validated value objects only.
     *
     * THE THREE CASES ARE DISTINGUISHED BY THE CONSTRAINT TOKEN, NOT BY PROSE.
     * `required`, `single-token` and `equals=<configured>` are machine-readable, and
     * there is no place in a {@see ValidationDiagnostic} for a sentence — which is
     * the point: {@see \Civi\Dfc\V2\Controller\Error\ValidationIssue::title()} is a
     * literal from the closed vocabulary, so the client gets one of three texts and
     * a machine-readable discriminator, not a server-authored explanation.
     */
    private static function failure(ValidationIssue $issue, string $constraint): DfcApiException
    {
        return DfcApiException::of(ProtocolError::unprocessable(
            [new ValidationDiagnostic(DfcPath::fromTokens(self::NAME), self::NAME, $issue, $constraint)],
            DfcPath::fromTokens(self::NAME)
        ));
    }
}