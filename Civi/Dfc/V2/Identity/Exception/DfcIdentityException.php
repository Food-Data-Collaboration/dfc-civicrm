<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Identity\Exception;

/**
 * Marker interface for every exception thrown by the URI/namespace layer.
 *
 * WHY A MARKER INTERFACE AND NOT A BASE CLASS
 *   Callers (sa-005's single error model, sa-006's visibility policy, the
 *   controllers) need to distinguish "this URI is unusable" from every other
 *   failure without depending on CiviCRM. A marker interface costs nothing and
 *   leaves the concrete classes free to extend the SPL exception that actually
 *   describes the failure, which is what makes them testable with PHPUnit's
 *   expectException() rather than a bespoke harness.
 *
 * WHAT THIS LAYER GUARANTEES
 *   Every failure mode below is a *programming or configuration* error detected
 *   before anything is persisted or emitted. There is no "not found" case here:
 *   this layer mints URIs, it does not resolve them.
 *
 * @package Civi\Dfc
 */
interface DfcIdentityException extends \Throwable
{
}