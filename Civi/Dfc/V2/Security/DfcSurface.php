<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Security;

/**
 * The surfaces this interface serves, as the authorisation model sees them.
 *
 * ============================================================================
 * WHY THIS IS A CLOSED ENUM AND NOT A URL PREFIX
 * ============================================================================
 * A scope maps to "read on the Organization surface", and "the Organization
 * surface" has to mean one thing everywhere. Deriving it from a URL prefix would
 * couple authorisation to a routing decision (see PRD-002 "Upstream refresh"
 * item 5: the two published specs disagree about path casing and shape, and
 * `dfc-ldp.yaml` is still gaining operations). If a surface were a prefix, every
 * path change would silently change who may read what.
 *
 * So the surface set is fixed here, and it is the DFC *resource* inventory from
 * PRD-002 §4.1 restricted to what is actually in scope — note that no Product
 * or Order surface appears, because PRD-002 §1 excludes them and a surface that
 * nothing serves must not be authorisable.
 *
 * ============================================================================
 * SURFACES, AND WHAT EACH ONE COVERS
 * ============================================================================
 *  - PLATFORM_WEBID    the platform profile document that advertises the API.
 *  - ORGANIZATION      dfc-b:Organization / dfc-b:Enterprise and its container.
 *  - PERSON            dfc-b:Person.
 *  - ADDRESS           dfc-b:Address and the address-bearing place values.
 *  - PHONE_NUMBER      dfc-b:PhoneNumber.
 *  - SOCIAL_MEDIA      dfc-b:SocialMedia.
 *  - PLACE             the dfc-b:Place family (physical / virtual).
 *  - WEBID             user and Organization WebID profiles and TypeIndexes.
 *  - CONTAINER         LDP container membership: `ldp:contains` and friends.
 *  - IDENTITY_SERVICE  the Identity Service endpoint that answers 303.
 *
 * IDENTITY_SERVICE is a surface rather than a special case because it is the one
 * operation whose output is a *location*, and it must be authorisable
 * independently: an identity may legitimately be allowed to discover its own
 * WebID through the Identity Service without being allowed to read an
 * organization's catalog.
 *
 * @package Civi\Dfc
 */
enum DfcSurface: string
{
    case PLATFORM_WEBID = 'platform_webid';
    case ORGANIZATION = 'organization';
    case PERSON = 'person';
    case ADDRESS = 'address';
    case PHONE_NUMBER = 'phone_number';
    case SOCIAL_MEDIA = 'social_media';
    case PLACE = 'place';
    case WEBID = 'webid';
    case CONTAINER = 'container';
    case IDENTITY_SERVICE = 'identity_service';

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return self::cases();
    }

    public function title(): string
    {
        return match ($this) {
            self::PLATFORM_WEBID => 'the platform WebID profile',
            self::ORGANIZATION => 'Organization resources',
            self::PERSON => 'Person resources',
            self::ADDRESS => 'Address resources',
            self::PHONE_NUMBER => 'PhoneNumber resources',
            self::SOCIAL_MEDIA => 'SocialMedia resources',
            self::PLACE => 'Place resources',
            self::WEBID => 'WebID profiles and TypeIndexes',
            self::CONTAINER => 'LDP container membership',
            self::IDENTITY_SERVICE => 'the Identity Service',
        };
    }
}