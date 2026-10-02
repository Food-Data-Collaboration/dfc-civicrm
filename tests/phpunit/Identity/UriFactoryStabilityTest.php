<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Identity;

use Civi\Dfc\V2\Identity\DfcReleaseConfig;
use Civi\Dfc\V2\Identity\UriFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * URI stability: the policy, asserted rather than asserted-in-prose.
 *
 * Everything here is the answer to PRD-002's "URI factory returns stable URIs
 * across hostname change" plus learning goal "identity-model durability".
 *
 * The plain-language summary of what these tests establish:
 *
 *   1. Renaming a contact does NOT change its URI. Nothing derived from a display
 *      name ever reaches a URI, because the URI factory is not given a display
 *      name to use — it is given the opaque identifier, and the only way to
 *      influence a URI is to change the identifier, which is a create-once
 *      operation owned by the identity table.
 *
 *   2. A DFC version upgrade does NOT change any URI.
 *
 *   3. A CiviCRM id change does NOT change any URI, because the CiviCRM id never
 *      enters the URI.
 *
 *   4. A hostname change DOES change every URI. This is asserted as a fact, not
 *      wished away, and the tests also assert the two things that make that
 *      survivable: the opaque identifier is recoverable from the old URI, and a
 *      factory built for the old authority still resolves it.
 *
 *   5. Two factories built independently from the same configuration produce
 *      byte-identical URIs for the same input. That is what makes an `@id` a
 *      cacheable, mergeable, testable value.
 */
#[CoversClass(UriFactory::class)]
final class UriFactoryStabilityTest extends TestCase
{
    use IdentityFixtureTrait;



    private function factory(string $baseUri = IdentityFixtures::DEFAULT_BASE_URI): UriFactory
    {
        return new UriFactory(self::fixtureConfig($baseUri));
    }

    // -- 1. Renaming a contact does not change its URI ------------------------

    /**
     * The rename scenario, end to end.
     *
     * `beforeName` and `afterName` are never passed to the URI factory, which is
     * the point: the factory's only per-record input is the opaque key. Two
     * contacts with the same key are the same identity even as their names move.
     */
    public function testRenamingAContactDoesNotChangeItsUri(): void
    {
        $factory = $this->factory();

        // Two states of the same CiviCRM row: every mutable field has moved, the
        // identity column has not.
        $before = [
            'dfc_key' => IdentityFixtures::USER_KEY,
            'display_name' => 'John Smith',
            'sort_name' => 'Smith, John',
            'email' => 'john@example.org',
        ];
        $after = [
            'dfc_key' => IdentityFixtures::USER_KEY,
            'display_name' => 'Dr. Jonathan Smith-Bergström',
            'sort_name' => 'Bergström, Jonathan',
            'email' => 'j.smith@example.com',
        ];

        self::assertNotSame($before['display_name'], $after['display_name']);

        $uriBefore = $factory->userWebId($before['dfc_key']);
        $uriAfter = $factory->userWebId($after['dfc_key']);

        self::assertSame($uriBefore, $uriAfter);
        self::assertSame('https://platform.example/dfc/v2/users/' . IdentityFixtures::USER_KEY . '/webid', $uriAfter);

        // Nothing mutable, raw or encoded, is anywhere in the URI.
        foreach (array_merge($after, ['sort_name' => $after['sort_name']]) as $field => $value) {
            if ($field === 'dfc_key') {
                continue;
            }
            self::assertStringNotContainsString($value, $uriAfter, $field . ' leaked into the URI');
            self::assertStringNotContainsString(rawurlencode($value), $uriAfter, $field . ' leaked encoded');
        }
    }

    public function testRenamingAnOrganizationDoesNotChangeItsUris(): void
    {
        $factory = $this->factory();

        // "Acme Corporation" -> "Acme Global Holdings"; the key is untouched.
        self::assertSame(
            $factory->organizationWebId(IdentityFixtures::ORGANIZATION_KEY),
            $factory->organizationWebId(IdentityFixtures::ORGANIZATION_KEY)
        );
        self::assertStringNotContainsString(
            rawurlencode('Acme Corporation'),
            $factory->organizationWebId(IdentityFixtures::ORGANIZATION_KEY) . $factory->organizationIndex(IdentityFixtures::ORGANIZATION_KEY)
        );
    }

    /**
     * The same identity keeps the same URI across a CiviCRM-side import.
     *
     * A re-import or a merge allocates a new integer `civicrm_contact.id`. If the
     * URI contained that integer, every merged or re-imported record would fork
     * its public identity. Asserting that two DIFFERENT CiviCRM ids and the SAME
     * opaque key produce the SAME URI is the merge-safety property.
     */
    public function testACiviCrmIdChangeDoesNotChangeTheUri(): void
    {
        $factory = $this->factory();

        // $civiCrmId is present in the row and simply not a factory argument.
        $rowBeforeMerge = ['civicrm_contact_id' => 1042, 'dfc_key' => IdentityFixtures::USER_KEY];
        $rowAfterMerge = ['civicrm_contact_id' => 9987, 'dfc_key' => IdentityFixtures::USER_KEY];

        self::assertSame(
            $factory->userWebId($rowBeforeMerge['dfc_key']),
            $factory->userWebId($rowAfterMerge['dfc_key'])
        );
        self::assertStringNotContainsString('1042', $factory->userWebId($rowAfterMerge['dfc_key']));
        self::assertStringNotContainsString('9987', $factory->userWebId($rowAfterMerge['dfc_key']));
    }

    /**
     * The factory has no path at all that appends a record-id-derived segment:
     * the only per-record input is the single opaque identifier, so there is
     * nothing else in a semantic URI that could vary between two rows.
     */
    public function testNoSemanticUriContainsACiviCrmId(): void
    {
        $factory = $this->factory();

        self::assertSame(
            'https://platform.example/dfc/v2/semantic/person/' . IdentityFixtures::USER_KEY,
            $factory->semanticResourceUri('person', IdentityFixtures::USER_KEY)
        );
        self::assertSame(
            'https://platform.example/dfc/v2/semantic/person',
            substr(
                $factory->semanticResourceUri('person', IdentityFixtures::USER_KEY),
                0,
                strlen('https://platform.example/dfc/v2/semantic/person')
            )
        );
    }

    // -- 2. A DFC version upgrade does not change any URI ---------------------

    public function testDfcVersionUpgradeDoesNotChangeAnyUri(): void
    {
        $descriptor = self::fixtureDescriptor();
        $descriptor['dfc_ontology_version'] = '2.1.0';
        $descriptor['ontology']['business_url'] = 'https://w3id.org/dfc/ontology/v2.1.0/src/DFC_BusinessOntology.rdf';
        $descriptor['ontology']['technical_url'] = 'https://w3id.org/dfc/ontology/v2.1.0/src/DFC_TechnicalOntology.rdf';

        $before = new UriFactory(self::fixtureConfig());
        $after = new UriFactory(DfcReleaseConfig::fromDescriptorArray(
            $descriptor,
            ['platform_base_uri' => IdentityFixtures::DEFAULT_BASE_URI]
        ));

        foreach (
            [
                [$before, $after, 'platformWebId', []],
                [$before, $after, 'platformSubject', []],
                [$before, $after, 'identityService', []],
                [$before, $after, 'userWebId', [IdentityFixtures::USER_KEY]],
                [$before, $after, 'userSubject', [IdentityFixtures::USER_KEY]],
                [$before, $after, 'organizationWebId', [IdentityFixtures::ORGANIZATION_KEY]],
                [$before, $after, 'organizationContainer', [IdentityFixtures::ORGANIZATION_KEY]],
                [$before, $after, 'organizationIndex', [IdentityFixtures::ORGANIZATION_KEY]],
                [$before, $after, 'semanticResourceUri', ['address', IdentityFixtures::USER_KEY]],
                [$before, $after, 'ldpContainerUri', [UriFactory::COLLECTION_ORGANIZATIONS, IdentityFixtures::ORGANIZATION_KEY]],
            ] as [$a, $b, $method, $args]
        ) {
            self::assertSame(
                $a->{$method}(...$args),
                $b->{$method}(...$args),
                $method . '() changed when the DFC version moved'
            );
        }
    }

    // -- 3. Determinism across independent factory instances -------------------

    public function testTwoIndependentFactoriesProduceIdenticalUris(): void
    {
        $first = $this->factory();
        $second = $this->factory();

        self::assertNotSame($first, $second, 'two distinct instances, on purpose');

        foreach (
            [
                ['platformWebId', []],
                ['platformSubject', []],
                ['identityService', []],
                ['userWebId', [IdentityFixtures::USER_KEY]],
                ['userSubject', [IdentityFixtures::USER_KEY]],
                ['organizationWebId', [IdentityFixtures::ORGANIZATION_KEY]],
                ['organizationSubject', [IdentityFixtures::ORGANIZATION_KEY]],
                ['organizationContainer', [IdentityFixtures::ORGANIZATION_KEY]],
                ['organizationIndex', [IdentityFixtures::ORGANIZATION_KEY]],
                ['semanticResourceUri', ['physical-place', IdentityFixtures::ORGANIZATION_KEY]],
                ['ldpContainerUri', [UriFactory::COLLECTION_USERS, IdentityFixtures::USER_KEY]],
            ] as [$method, $args]
        ) {
            self::assertSame(
                $first->{$method}(...$args),
                $second->{$method}(...$args),
                $method . '() is not deterministic across instances'
            );
        }
    }

    public function testRepeatedCallsOnOneFactoryAreIdempotent(): void
    {
        $factory = $this->factory();

        self::assertSame($factory->userWebId(IdentityFixtures::USER_KEY), $factory->userWebId(IdentityFixtures::USER_KEY));
        self::assertSame($factory->userWebId(IdentityFixtures::USER_KEY), $factory->userWebId(IdentityFixtures::USER_KEY));
        self::assertSame(
            $factory->semanticResourceUri('address', IdentityFixtures::ORGANIZATION_KEY),
            $factory->semanticResourceUri('address', IdentityFixtures::ORGANIZATION_KEY)
        );
    }

    public function testInjectedGeneratorsDoNotAffectUriForAKnownIdentifier(): void
    {
        // Minting is delegated; resolution of an EXISTING identifier is not.
        // Two generators cannot disagree about the URI of a stored identifier.
        $withScript = new UriFactory(self::fixtureConfig(), new ScriptedIdentifierGenerator(['SCRIPTED00000000000000000X']));
        $withDefault = $this->factory();

        self::assertSame(
            $withDefault->userWebId(IdentityFixtures::USER_KEY),
            $withScript->userWebId(IdentityFixtures::USER_KEY)
        );
    }

    // -- 4. Hostname change: URIs change, the identifier does not -------------

    public function testHostnameChangeChangesEveryUriButNotTheIdentifier(): void
    {
        $old = $this->factory('https://old-platform.example/dfc/v2');
        $new = $this->factory('https://new-platform.example/dfc/v2');

        self::assertNotSame(
            $old->userWebId(IdentityFixtures::USER_KEY),
            $new->userWebId(IdentityFixtures::USER_KEY),
            'the authority is part of the string, so the URIs necessarily differ'
        );

        // What survives: the shape, and the identifier inside both URIs.
        self::assertSame(
            substr($old->userWebId(IdentityFixtures::USER_KEY), strlen('https://old-platform.example')),
            substr($new->userWebId(IdentityFixtures::USER_KEY), strlen('https://new-platform.example')),
            'only the authority may differ'
        );
        self::assertSame(IdentityFixtures::USER_KEY, $old->matchUserKey($old->userWebId(IdentityFixtures::USER_KEY)));
        self::assertSame(IdentityFixtures::USER_KEY, $new->matchUserKey($new->userWebId(IdentityFixtures::USER_KEY)));
        self::assertSame(IdentityFixtures::ORGANIZATION_KEY, $old->matchOrganizationKey($old->organizationWebId(IdentityFixtures::ORGANIZATION_KEY)));
        self::assertSame(IdentityFixtures::ORGANIZATION_KEY, $new->matchOrganizationKey($new->organizationWebId(IdentityFixtures::ORGANIZATION_KEY)));
    }

    /**
     * The compatibility obligation, stated as a test.
     *
     * While the OLD authority still serves, a factory built for it must keep
     * resolving its URIs to the same identifiers. This is what makes a redirect
     * or alias on the old host sufficient — and it is a deployment obligation,
     * not something the URI factory can perform by itself.
     */
    public function testALegacyFactoryForTheOldAuthorityStillResolvesItsOwnUris(): void
    {
        $legacy = $this->factory('https://old-platform.example/dfc/v2');

        $legacyUserUri = $legacy->userWebId(IdentityFixtures::USER_KEY);
        $legacyOrgUri = $legacy->organizationWebId(IdentityFixtures::ORGANIZATION_KEY);
        $legacySemanticUri = $legacy->semanticResourceUri('address', IdentityFixtures::ORGANIZATION_KEY);

        self::assertSame(IdentityFixtures::USER_KEY, $legacy->matchUserKey($legacyUserUri));
        self::assertSame(IdentityFixtures::ORGANIZATION_KEY, $legacy->matchOrganizationKey($legacyOrgUri));
        self::assertSame(IdentityFixtures::ORGANIZATION_KEY, $legacy->matchSemanticIdentifier($legacySemanticUri));

        // Subjects work too, because the Identity Service redirects to one.
        self::assertSame(IdentityFixtures::USER_KEY, $legacy->matchUserKey($legacy->userSubject(IdentityFixtures::USER_KEY)));
    }

    /**
     * The dangerous half of the hostname change, asserted explicitly.
     *
     * A factory for the NEW authority does NOT recognise the OLD authority's
     * URIs. Reconciliation must therefore consult the recorded legacy base, or it
     * will see an unknown WebID and mint a second identity for a record it
     * already has — the silent-merge failure PRD-002 CP-6 warns about, in its
     * duplicate-identity form.
     */
    public function testFactoriesForDifferentAuthoritiesDoNotRecogniseEachOther(): void
    {
        $old = $this->factory('https://old-platform.example/dfc/v2');
        $new = $this->factory('https://new-platform.example/dfc/v2');

        $oldUserUri = $old->userWebId(IdentityFixtures::USER_KEY);

        self::assertTrue($old->recognises($oldUserUri));
        self::assertTrue($old->recognises($old->userSubject(IdentityFixtures::USER_KEY)));

        self::assertFalse($new->recognises($oldUserUri));
        self::assertNull($new->matchUserKey($oldUserUri));
        self::assertNull($new->matchSemanticIdentifier($old->semanticResourceUri('address', IdentityFixtures::USER_KEY)));
    }

    public function testRecognisesRejectsAForeignAuthorityAndTheBaseItself(): void
    {
        $factory = $this->factory();

        self::assertFalse($factory->recognises('https://elsewhere.example/dfc/v2/users/' . IdentityFixtures::USER_KEY . '/webid'));
        self::assertFalse($factory->recognises('https://platform.example/other/users/' . IdentityFixtures::USER_KEY . '/webid'));
        self::assertFalse($factory->recognises('https://platform.example/dfc/v2/'), 'the base itself is not a resource');
        self::assertTrue($factory->recognises($factory->identityService()));
    }

    public function testANewAuthorityDerivedFromTheSameDescriptorKeepsEveryRelativeShape(): void
    {
        $before = self::fixtureConfig('https://old-platform.example/dfc/v2');
        $after = $before->withPlatformBaseUri('https://new-platform.example/dfc/v2/');

        $oldFactory = new UriFactory($before);
        $newFactory = new UriFactory($after);

        self::assertSame($oldFactory->userWebId(IdentityFixtures::USER_KEY), 'https://old-platform.example/dfc/v2/users/' . IdentityFixtures::USER_KEY . '/webid');
        self::assertSame($newFactory->userWebId(IdentityFixtures::USER_KEY), 'https://new-platform.example/dfc/v2/users/' . IdentityFixtures::USER_KEY . '/webid');
        self::assertSame($oldFactory->organizationIndex(IdentityFixtures::ORGANIZATION_KEY), $oldFactory->organizationContainer(IdentityFixtures::ORGANIZATION_KEY) . 'index');
        self::assertSame($newFactory->organizationIndex(IdentityFixtures::ORGANIZATION_KEY), $newFactory->organizationContainer(IdentityFixtures::ORGANIZATION_KEY) . 'index');
    }

    // -- Round-trip: the identifier in the URI is the identifier in the store --

    public function testEveryMintedWebIdResolvesBackToItsOwnIdentifier(): void
    {
        $factory = $this->factory();

        self::assertSame(IdentityFixtures::USER_KEY, $factory->matchUserKey($factory->userWebId(IdentityFixtures::USER_KEY)));
        self::assertSame(IdentityFixtures::USER_KEY, $factory->matchUserKey($factory->userSubject(IdentityFixtures::USER_KEY)));
        self::assertSame(IdentityFixtures::ORGANIZATION_KEY, $factory->matchOrganizationKey($factory->organizationWebId(IdentityFixtures::ORGANIZATION_KEY)));
        self::assertSame(IdentityFixtures::ORGANIZATION_KEY, $factory->matchOrganizationKey($factory->organizationSubject(IdentityFixtures::ORGANIZATION_KEY)));
        self::assertSame(
            IdentityFixtures::ORGANIZATION_KEY,
            $factory->matchSemanticIdentifier($factory->semanticResourceUri('address', IdentityFixtures::ORGANIZATION_KEY))
        );
    }

    public function testMatchersRefuseUriShapesTheyDoNotOwn(): void
    {
        $factory = $this->factory();

        // A user URI is not an organization URI.
        self::assertNull($factory->matchOrganizationKey($factory->userWebId(IdentityFixtures::USER_KEY)));
        // The organization index resource is not the organization WebID.
        self::assertNull($factory->matchOrganizationKey($factory->organizationIndex(IdentityFixtures::ORGANIZATION_KEY)));
        // A foreign authority.
        self::assertNull($factory->matchUserKey('https://elsewhere.example/dfc/v2/users/' . IdentityFixtures::USER_KEY . '/webid'));
        // Nothing under our base.
        self::assertNull($factory->matchUserKey($factory->identityService()));
        // A semantic URI with the wrong number of segments.
        self::assertNull($factory->matchSemanticIdentifier('https://platform.example/dfc/v2/semantic/address'));
        self::assertNull($factory->matchSemanticIdentifier(
            'https://platform.example/dfc/v2/semantic/address/' . IdentityFixtures::ORGANIZATION_KEY . '/extra'
        ));
    }

    public function testMatchSurvivesTheSubjectFragmentOnASemanticUri(): void
    {
        $factory = $this->factory();

        self::assertSame(
            IdentityFixtures::ORGANIZATION_KEY,
            $factory->matchSemanticIdentifier(
                $factory->semanticResourceUri('address', IdentityFixtures::ORGANIZATION_KEY) . '#something'
            )
        );
    }
}