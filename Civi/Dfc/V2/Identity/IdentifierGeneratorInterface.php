<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Identity;

/**
 * Mints the opaque identifiers that DFC URIs are built from.
 *
 * ============================================================================
 * THE CONTRACT, AND WHY IT IS WRITTEN THIS WAY
 * ============================================================================
 *
 * An identifier produced here MUST satisfy all of the following. These are not
 * preferences; each one rules out a specific way identity breaks in CiviCRM.
 *
 * I1. UNRELATED TO ANY MUTABLE ATTRIBUTE.
 *      Not the contact's display name, sort name, email, external identifier,
 *      postal code, phone number, or organisation label. Contacts get renamed,
 *      married, re-spelled and merged; none of that may reach a URI. This is
 *      the single most important property, because CiviCRM's own identifiers
 *      (integer `id`, `hash`) are exactly the things that DO get copied around
 *      and re-created on import.
 *
 * I2. INDEPENDENT OF THE HOSTNAME AND OF THE DFC VERSION.
 *      Neither is under the record's control. See DfcReleaseConfig R1/R3.
 *
 * I3. COLLISION-RESISTANT.
 *      Two records minted in the same second must not collide, and a collision
 *      here silently aliases two people's identities in a public, dereferenceable
 *      graph. 128 bits of CSPRNG output makes that impossible in practice.
 *
 * I4. URI-SAFE BY CONSTRUCTION.
 *      Crockford base32, uppercase alphanumeric only, so no encoding is ever
 *      needed and the identifier is legible in a log or a bug report.
 *
 * I5. DETERMINISM IS INJECTABLE, NOT ASSUMED.
 *      Tests must be able to assert exact URI strings, so the generator is an
 *      injected collaborator. Production uses
 *      {@see RandomIdentifierGenerator}; tests inject a counting or scripted
 *      implementation. Nothing else in the URI factory calls random().
 *
 * WHAT IT DELIBERATELY DOES NOT GUARANTEE
 *      Ordering. These identifiers are NOT guaranteed to sort by creation time.
 *      Reconciliation and pagination must use a stored timestamp, never an
 *      identifier comparison. A ULID-style time-ordered identifier was rejected:
 *      creation time is not secret, it is metadata an unauthenticated reader of a
 *      WebID must not be able to infer.
 *
 * @package Civi\Dfc
 *
 * @see UriFactory::generateIdentifier() and UriFactory::newSemanticResourceUri()
 */
interface IdentifierGeneratorInterface
{
    /**
     * Mint one identifier.
     *
     * @param string $kind Logical namespace, e.g. 'user', 'organization',
     *                     'address', 'phone-number'. Implementations MAY use it to
     *                     partition their state; it never appears in the output.
     *                     Pass the CiviCRM entity kind, never the display name.
     *
     * @return string A non-empty, URI-safe opaque identifier. Must not contain
     *                '/', '#', '?', whitespace or any character that would need
     *                percent-encoding.
     */
    public function generate(string $kind): string;
}