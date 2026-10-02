<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Controller\Error;

/**
 * The one place an arbitrary PHP/PDO failure becomes a protocol error.
 *
 * PRD-002 §9 lists "OIDC validation done per-controller instead of centrally" as
 * a medium/high risk; the same argument applies to error mapping, and this class
 * is that centralisation for failures. There is no second mapper and no
 * `catch (Throwable $e) { ... }` block in a route that invents a status.
 *
 * ============================================================================
 * THE MAPPING, AND WHAT IS DELIBERATELY THROWN AWAY
 * ============================================================================
 * | input                                             | output                       |
 * |---------------------------------------------------|------------------------------|
 * | {@see DfcApiException}                            | its own {@see ProtocolError} |
 * | {@see \Civi\Dfc\V2\Identity\Exception\DfcIdentityException} | 500 `internal_error` |
 * | any PDO / database exception                      | 500 `internal_error`         |
 * | `\JsonException`, `\TypeError`, `\Error`          | 500 `internal_error`         |
 * | everything else                                   | 500 `internal_error`         |
 *
 * `$e->getMessage()`, `getFile()`, `getLine()`, `getTraceAsString()` and
 * `getPrevious()` are never read. That is the entire mechanism of the no-leak
 * guarantee for unexpected failures: the information does not leave this method,
 * not because it is filtered but because it is never taken. What leaves instead
 * is a correlation id, which sa-014's audit layer uses to find the same throwable
 * in the server log.
 *
 * ============================================================================
 * WHY EVERYTHING ELSE IS 500 AND NOT SOMETHING SMARTER
 * ============================================================================
 * A mapper that guesses is worse than no mapper. `\JsonException` could be a
 * malformed *client* body (400) or a broken *serialiser* (500), and the mapper
 * cannot tell which without being told. Guessing 400 turns a server bug into a
 * client's fault — the failure mode a deterministic error model exists to
 * eliminate. So the guess is refused: a layer that knows the condition is client
 * input says so explicitly by calling {@see ProtocolError::malformedJsonLd()} and
 * lets {@see fromThrowable()} handle the genuine surprise.
 *
 * Identity-layer exceptions map to 500 for the same reason and with sa-004's own
 * blessing: `DfcIdentityException`'s docblock states that every one of its
 * failures is "a programming or configuration error detected before anything is
 * persisted or emitted". A route that turns *client* input into one (a bad path
 * key, say) must translate to 400 itself, before calling the factory.
 *
 * ============================================================================
 * THIS METHOD CANNOT THROW
 * ============================================================================
 * An error handler that throws is an outage amplifier. The body is wrapped, and
 * a mapper that somehow fails still yields a 500 with a correlation id.
 *
 * @package Civi\Dfc
 */
final class ErrorMapper
{
    private readonly ?CorrelationId $correlationId;

    private readonly string $realm;

    /**
     * @param CorrelationId|null $correlationId Attached to every error, so a user
     *        can quote one string that appears in the response AND in the log.
     * @param string             $realm         The `WWW-Authenticate` realm,
     *        normally `DfcReleaseConfig::platformBaseUri()`. Required so the 401
     *        this mapper produces is RFC 9110-conformant without the caller
     *        having to remember.
     *
     * @throws \InvalidArgumentException when $realm is not an absolute https URI.
     */
    public function __construct(?CorrelationId $correlationId = null, string $realm = 'https://dfc.invalid/')
    {
        // Validate once, here, rather than on every 401: the realm is echoed into
        // a response header, so it must be known-safe before it is stored.
        AuthenticateChallenge::bearer($realm);

        $this->correlationId = $correlationId;
        $this->realm = $realm;
    }

    public function correlationId(): ?CorrelationId
    {
        return $this->correlationId;
    }

    /**
     * Map any throwable to the error a client should see.
     *
     * Never throws, never reads the throwable's text.
     */
    public function fromThrowable(\Throwable $throwable): ProtocolError
    {
        try {
            if ($throwable instanceof DfcApiException) {
                // Already a protocol condition. Re-emit verbatim, but backfill the
                // correlation id if the thrower did not have one — otherwise the
                // log and the response cannot be joined.
                $error = $throwable->error();

                if ($error->correlationId() !== null || $this->correlationId === null) {
                    return $error;
                }

                return $this->withCorrelationId($error);
            }

            return $this->internalError();
        } catch (\Throwable $mappingFailure) {
            // Unreachable in practice. Reached, it must still produce an error
            // rather than propagate: a response with no correlation id is better
            // than no response at all.
            return ProtocolError::internalError($this->correlationId);
        }
    }

    /**
     * A plain 500.
     *
     * Public because a route that catches something it cannot classify should
     * call this explicitly rather than reaching for `new ProtocolError(...)`.
     */
    public function internalError(): ProtocolError
    {
        return ProtocolError::internalError($this->correlationId);
    }

    /** 401 — nothing usable was presented. */
    public function authenticationRequired(?string $error = null, ?string $scope = null): ProtocolError
    {
        return ProtocolError::authenticationRequired(
            AuthenticateChallenge::bearer($this->realm, $error, $scope),
            $this->correlationId
        );
    }

    /** 401 — something was presented and rejected. */
    public function authenticationUnusable(string $error = 'invalid_token'): ProtocolError
    {
        return ProtocolError::authenticationUnusable(
            AuthenticateChallenge::bearer($this->realm, $error),
            $this->correlationId
        );
    }

    /**
     * Backfill a correlation id onto an error that was built without one.
     *
     * The rebuild is safe because every slot of {@see ProtocolError} is either a
     * validated value object or a closed-enum-derived literal.
     */
    private function withCorrelationId(ProtocolError $error): ProtocolError
    {
        $rebuilt = new ProtocolError(
            $error->code(),
            $error->path(),
            $error->diagnostics(),
            $this->correlationId,
            $error->challenge(),
            $error->supportedMediaTypes()
        );

        return $rebuilt;
    }
}
