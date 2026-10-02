<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Controller\Error;

use Civi\Dfc\V2\Controller\Error\LeakGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The serialiser's redaction stage, tested pattern by pattern.
 *
 * Two jobs: prove each signature fires on something it should, and prove none of
 * them fires on anything it should not. The second half is the one that keeps the
 * guard usable — a denylist that damages correct output gets switched off, and
 * then the primary structural control is the only thing standing between a PDO
 * message and a client.
 */
#[CoversClass(LeakGuard::class)]
final class LeakGuardTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function hostileProvider(): iterable
    {
        yield 'PHP open tag' => ['SQL started in <?php eval($_GET["x"]);', '<?php'];
        yield 'stack trace marker' => ['Stack trace: #0 {main}', 'Stack trace:'];
        yield 'SQLSTATE' => ['SQLSTATE[42S02]: no such table', 'SQLSTATE'];
        yield 'PDO exception class' => ['PDOException was thrown', 'PDOException'];
        yield 'PDO driver prefix' => ['PDOStatement->execute() failed', 'PDOStatement'];
        yield 'CiviCRM DAO class' => ['CRM_Core_DAO_Contact::query()', 'CRM_Core_DAO_Contact'];
        yield 'frame with line number' => [
            '#0 /var/www/html/sites/default/x.php(12): f()',
            '/var/www/html/sites/default/x.php',
        ];
        yield 'windows path' => ['C:\\inetpub\\sites\\app.config', 'C:\\inetpub'];
        yield 'php file reference' => ['require_once /opt/dfc/X.php;', 'X.php'];
        yield 'absolute path with extension' => ['{ "path": "/etc/civicrm/crm.ini" }', '/etc/civicrm/crm.ini'];
    }

    #[DataProvider('hostileProvider')]
    public function testEachSignatureRemovesWhatItIsMeantToRemove(string $text, string $mustVanish): void
    {
        $scrubbed = LeakGuard::scrub($text);

        self::assertStringNotContainsString($mustVanish, $scrubbed);
        self::assertStringContainsString(LeakGuard::REDACTION, $scrubbed);
        self::assertTrue(LeakGuard::containsLeak($text));
        self::assertFalse(LeakGuard::containsLeak($scrubbed));
    }

    /**
     * Every legitimate shape this layer can emit, checked against every signature.
     *
     * This is the false-positive contract. If a future pattern breaks it, this test
     * fails and the pattern is wrong — not the payload.
     *
     * @return iterable<string, array{string}>
     */
    public static function legitimateProvider(): iterable
    {
        yield 'a DFC context URL' => [
            '"@context":["https://w3id.org/dfc/ontology/v2.0.0/context/context_2.0.0.json",'
            . '{"ldp":"http://www.w3.org/ns/ldp#"}]',
        ];

        yield 'a container URI' => [
            '"@id":"https://platform.example/dfc/v2/organizations/01HZY9B2W8R6K4M0P1Q3S5T7V/"',
        ];

        yield 'a semantic resource URI' => [
            '"https://platform.example/dfc/v2/semantic/address/01HZY8QK3M7X4V2N6T9B0C5D8E"',
        ];

        yield 'a three-token DFC path' => ['"path":"/organizations/0/name"'];

        yield 'a deep DFC path' => [
            '"path":"/organizations/0/address/dfc-b:streetAddress/dfc-b:value"',
        ];

        yield 'a CURIE predicate' => ['"predicate":"dfc-b:vatStatus"'];

        yield 'a problem type URN' => ['"type":"urn:dfc-civicrm:error:validation_failed"'];

        yield 'an error detail sentence' => [
            '"detail":"The document is valid JSON-LD but does not describe a valid DFC resource."',
        ];

        yield 'a Link header value' => [
            'Link: <https://platform.example/dfc/v2/organizations/01HZY9B2W8R6K4M0P1Q3S5T7V/index>; '
            . 'rel="type"; type="application/ld+json"',
        ];

        yield 'a page link' => [
            '<https://platform.example/dfc/v2/organizations/01HZY9B2W8R6K4M0P1Q3S5T7V/?limit=20&offset=20>; '
            . 'rel="next"',
        ];

        yield 'an ETag' => ['ETag: "sha256-0123456789abcdef0123456789abcdef"'];

        yield 'an entity tag list' => ['If-Match: "sha256-0123456789abcdef", W/"sha256-fedcba9876543210"'];

        yield 'a media type list' => ['Accept-Post: application/ld+json, text/turtle'];

        yield 'a WWW-Authenticate challenge' => [
            'WWW-Authenticate: Bearer realm="https://platform.example/dfc/v2", error="invalid_token"',
        ];

        yield 'a supported media type list' => ['["application/ld+json","text/turtle"]'];

        yield 'a violation report' => [
            '[{"constraint":"minCount>=1","detail":"This predicate is required.","issue":"required",'
            . '"path":"/organizations/0/name","predicate":"dfc-b:name"}]',
        ];
    }

    #[DataProvider('legitimateProvider')]
    public function testNoLegitimatePayloadIsDamaged(string $payload): void
    {
        self::assertSame(
            $payload,
            LeakGuard::scrub($payload),
            'A correct DFC payload must survive the guard byte for byte.'
        );
        self::assertFalse(LeakGuard::containsLeak($payload));
    }

    public function testScrubbingIsIdempotent(): void
    {
        $hostile = 'SQLSTATE[42S02] in /var/www/html/app.php line 12 for id 12345';

        $once = LeakGuard::scrub($hostile);

        self::assertSame($once, LeakGuard::scrub($once));
    }

    public function testTheRedactionMarkerIsItselfInert(): void
    {
        self::assertFalse(LeakGuard::containsLeak(LeakGuard::REDACTION));
        self::assertStringNotContainsString('.php', LeakGuard::REDACTION);
        self::assertStringNotContainsString('SQLSTATE', LeakGuard::REDACTION);
    }

    public function testEverySignatureIsExposedSoItCanBeReviewedAndTested(): void
    {
        $signatures = LeakGuard::signatures();

        self::assertNotEmpty($signatures);

        foreach ($signatures as $label => $pattern) {
            self::assertIsString($label);
            // A malformed pattern fails silently in production and loudly in CI, so
            // this asserts each one compiles.
            self::assertIsInt(@preg_match($pattern, 'probe'), sprintf(
                'The "%s" signature is not a valid regular expression: %s',
                $label,
                $pattern
            ));
        }
    }
}
