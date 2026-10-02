<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Controller\Error;

/**
 * The only exception in this layer that reaches a client, and the only one whose
 * payload is a {@see ProtocolError}.
 *
 * ============================================================================
 * WHY THE CONSTRUCTOR ACCEPTS NOTHING ELSE
 * ============================================================================
 * A conventional `HttpException(string $message, int $status)` is exactly the
 * shape the no-leak guarantee must not allow: somewhere, inevitably, a route
 * writes `throw new DfcApiException($e->getMessage(), 500)` and a PDO message
 * becomes an HTTP response. There is no `$message` parameter here, and no
 * `Throwable` parameter, so that call cannot be written. A layer holding a raw
 * exception has exactly two moves:
 *
 *   - it already knows the protocol condition and calls a named constructor on
 *     {@see ProtocolError} (the useful case), or
 *   - it does not, and hands the throwable to {@see ErrorMapper} (the safe
 *     default), which produces a 500 with a correlation id and no detail.
 *
 * `\RuntimeException` is the SPL base because "this request cannot proceed" is a
 * runtime condition, not an argument or state error — both of which would tell a
 * reader the caller is wrong, which is not what is happening.
 *
 * @package Civi\Dfc
 */
final class DfcApiException extends \RuntimeException implements DfcProtocolException
{
    private readonly ProtocolError $error;

    public function __construct(ProtocolError $error, ?\Throwable $previous = null)
    {
        parent::__construct(
            sprintf('%d %s: %s', $error->status(), $error->codeValue(), $error->title()),
            $error->status(),
            $previous
        );

        $this->error = $error;
    }

    /**
     * The client-facing error. Everything a route needs to render a response.
     */
    public function error(): ProtocolError
    {
        return $this->error;
    }

    public function status(): int
    {
        return $this->error->status();
    }

    public function codeValue(): string
    {
        return $this->error->codeValue();
    }

    /**
     * Build the exception for an already-classified error.
     *
     * Exists so call sites read `throw DfcApiException::of($error)`, which makes it
     * visually obvious at the call site that the payload is a validated value object
     * and not a string. Returning rather than throwing keeps the control flow in the
     * caller's hands, which is what lets {@see \Civi\Dfc\V2\Controller\Http\IfMatchEvaluator}
     * express its outcome mapping as a single `match`.
     */
    public static function of(ProtocolError $error, ?\Throwable $previous = null): self
    {
        return new self($error, $previous);
    }
}
