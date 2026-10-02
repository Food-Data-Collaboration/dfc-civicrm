<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Controller\Error;

/**
 * Marker interface for every exception thrown by the HTTP protocol layer.
 *
 * Mirrors {@see \Civi\Dfc\V2\Identity\Exception\DfcIdentityException} and exists
 * for the same reason: the thin route layer needs to tell "this is a protocol
 * failure I should render as a problem document" from every other failure
 * without depending on CiviCRM, a PSR-7 implementation, or a particular
 * framework.
 *
 * IMPORTANT — THIS INTERFACE CARRIES NO PAYLOAD
 *   The one exception class that reaches a client, {@see DfcApiException}, can
 *   only be constructed from an already-serialised {@see ProtocolError}. It has
 *   no `setMessage()`/`__construct(string $message)` path, so no code in this
 *   repository — and no code in any lane that imports it — can hand a raw string
 *   to a client-facing throwable. That structural property is the PRIMARY half
 *   of the no-leak guarantee; {@see LeakGuard} is the secondary half.
 *
 * @package Civi\Dfc
 */
interface DfcProtocolException extends \Throwable
{
}
