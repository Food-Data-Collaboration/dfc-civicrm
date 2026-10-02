<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Validation;

/**
 * Marker for the three pipeline-internal invariants a stage can break.
 *
 * ============================================================================
 * WHY THESE ARE NOT {@see \Civi\Dfc\V2\Controller\Error\ProtocolError}
 * ============================================================================
 * Because none of the three is a request the client got wrong. Each is a
 * programming or wiring error inside this layer:
 *
 *   - {@see NoDocumentToValidate}       a stage needed a body that is not there.
 *   - {@see NoAuthenticatedPrincipal}   a stage needed an identity before
 *                                       authentication ran, or on an anonymous
 *                                       request.
 *   - {@see MutationNotAuthorised}      something wrote before the pipeline said
 *                                       every check had passed.
 *
 * All three therefore propagate to
 * {@see \Civi\Dfc\V2\Controller\Error\ErrorMapper} and become a 500 with a
 * correlation id — which is correct, because the client cannot fix any of them.
 * Turning any of them into a 4xx would be telling a client its request was at
 * fault for this server's wiring.
 *
 * @package Civi\Dfc
 */
interface PipelineInvariantViolation extends \Throwable
{
}