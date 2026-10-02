<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Policy;

use Civi\Dfc\V2\Controller\Error\DfcApiException;
use Civi\Dfc\V2\Controller\Error\ProtocolError;
use Civi\Dfc\V2\Security\BearerAuthenticator;
use Civi\Dfc\V2\Validation\ValidationContext;
use Civi\Dfc\V2\Validation\ValidationResult;
use Civi\Dfc\V2\Validation\ValidationStage;
use Civi\Dfc\V2\Validation\ValidationStageInterface;

/**
 * The AUTHENTICATION stage: `Authorization` header in, principal forward.
 *
 * ============================================================================
 * WHY IT LIVES IN `Policy` AND NOT IN `Security`
 * ============================================================================
 * Because {@see BearerAuthenticator} knows nothing about
 * {@see ValidationContext}, and this class is the one line that joins them. It has to
 * live somewhere; putting it beside the stage enum keeps the join in the same
 * namespace as the contract it satisfies, and the alternative — making the
 * authenticator depend on the validation context — would put a pipeline concept
 * inside the security layer.
 *
 * ============================================================================
 * WHY IT CATCHES, RATHER THAN LETTING {@see DfcApiException} PROPAGATE
 * ============================================================================
 * Both routes produce the same response, so this is about control flow rather than
 * output. {@see BearerAuthenticator} throws a {@see DfcApiException} carrying an
 * already-built {@see ProtocolError}, and letting it propagate would reach
 * {@see \Civi\Dfc\V2\Controller\Error\ErrorResponder} and render exactly the right
 * 401 with the `WWW-Authenticate` challenge sa-005 built.
 *
 * Catching it here means the pipeline short-circuits through the same
 * {@see ValidationResult::failed()} path as every other stage, so the run object is
 * the single answer to "what happened" and a caller needs one code path rather than
 * two. The error is re-emitted VERBATIM, so the challenge cannot drift.
 *
 * ============================================================================
 * WHAT IT DOES NOT DO
 * ============================================================================
 * It does not decide authorisation. It establishes WHO the caller is and hands the
 * {@see \Civi\Dfc\V2\Security\AuthenticatedPrincipal} forward; whether that identity
 * may read or write a given surface is
 * {@see \Civi\Dfc\V2\Security\ScopePermissionRegistry}'s question, asked by the
 * AUTHORIZATION stage — which is lane-3, because the CiviCRM permission check needs
 * the CMS and this layer has no access to it.
 *
 * ============================================================================
 * THE PRINCIPAL IS CARRIED ON THE CONTEXT, NOT RETURNED
 * ============================================================================
 * {@see ValidationResult::passed()} takes the derived context, and
 * {@see \Civi\Dfc\V2\Validation\ValidationPipeline} threads it into the next stage.
 * So a later stage reads `$context->principal()` and nobody has to thread a fourth
 * value through the pipeline's signature.
 *
 * @package Civi\Dfc
 */
final class AuthenticateStage implements ValidationStageInterface
{
    private readonly BearerAuthenticator $authenticator;

    private readonly AuthorizationHeaderSource $headers;

    public function __construct(BearerAuthenticator $authenticator, AuthorizationHeaderSource $headers)
    {
        $this->authenticator = $authenticator;
        $this->headers = $headers;
    }

    public function stage(): ValidationStage
    {
        return ValidationStage::AUTHENTICATION;
    }

    public function run(ValidationContext $context): ValidationResult
    {
        $header = $this->headers->headerFor($context->method(), $context->requestUri());

        try {
            $principal = $this->authenticator->authenticate($header);
        } catch (DfcApiException $protocolFailure) {
            return ValidationResult::failed($this->stage(), $protocolFailure->error());
        }

        return ValidationResult::passed($this->stage(), $context->withPrincipal($principal));
    }

    /**
     * The 403 a route produces when the principal lacks the permission an operation
     * needs.
     *
     * Here so that "authenticated but not authorised" is produced by the same layer
     * that authenticated, and {@see ProtocolError::permissionDenied()} is the only
     * 403 on this surface — see {@see ProtocolError}'s note that this is
     * deliberately NOT a 404.
     */
    public function permissionDenied(): ProtocolError
    {
        return ProtocolError::permissionDenied();
    }
}