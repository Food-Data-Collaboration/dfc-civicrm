<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Controller\Ldp;

use Civi\Dfc\Test\Controller\ControllerFixtureTrait;
use Civi\Dfc\Test\Controller\ControllerFixtures;
use Civi\Dfc\V2\Controller\Error\DfcApiException;
use Civi\Dfc\V2\Controller\Error\ErrorCode;
use Civi\Dfc\V2\Controller\Ldp\ContainerPage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Bounded, deduplicated, stable container pages — and the boundary behaviour at
 * each end of the collection.
 */
#[CoversClass(ContainerPage::class)]
final class ContainerPageTest extends TestCase
{
    use ControllerFixtureTrait;

    /**
     * @param list<string> $members
     */
    private function page(array $members, int $limit = 20, int $offset = 0, ?int $total = null): ContainerPage
    {
        return new ContainerPage(self::organizationContainer(), $members, $limit, $offset, $total);
    }

    // -- The bound is applied BEFORE the fetch -------------------------------

    /**
     * The bound is the whole point of this class, so it is asserted where it can
     * actually go wrong: at the query. A source that is handed an unbounded request
     * and slices afterwards is the exact defect PRD-002 §10 asks about.
     */
    public function testTheLimitIsAppliedBeforeTheSourceIsAsked(): void
    {
        $source = new ScriptedMembershipSource(array_map(
            static fn (int $index): string => 'https://platform.example/dfc/v2/x/' . $index,
            range(1, 500)
        ));

        $page = ContainerPage::fromSource(self::organizationContainer(), $source, '5', '10');

        self::assertSame(
            [['container' => self::organizationContainer(), 'limit' => 5, 'offset' => 10]],
            $source->calls()
        );
        self::assertCount(5, $page->members());
    }

    public function testAnOverLargeLimitIsClampedRatherThanRefused(): void
    {
        $source = new ScriptedMembershipSource(array_map(
            static fn (int $index): string => 'https://platform.example/dfc/v2/x/' . $index,
            range(1, 500)
        ));

        $page = ContainerPage::fromSource(self::organizationContainer(), $source, '100000');

        self::assertSame(ContainerPage::MAX_LIMIT, $page->limit());
        self::assertSame(ContainerPage::MAX_LIMIT, $source->largestLimitRequested());
        self::assertTrue($page->limitWasClamped());
        self::assertCount(ContainerPage::MAX_LIMIT, $page->members());
    }

    public function testADeploymentMayLowerTheCeiling(): void
    {
        $source = new ScriptedMembershipSource(array_map(
            static fn (int $index): string => 'https://platform.example/dfc/v2/x/' . $index,
            range(1, 500)
        ));

        $page = ContainerPage::fromSource(self::organizationContainer(), $source, '80', null, 25);

        self::assertSame(25, $page->limit());
        self::assertTrue($page->limitWasClamped());
    }

    public function testAPageBuiltByHandIsNotMarkedClampedBecauseNothingWasClamped(): void
    {
        self::assertFalse($this->page([], 20)->limitWasClamped());
    }

    public function testAnAbsentLimitUsesTheDefault(): void
    {
        $source = new ScriptedMembershipSource([]);

        self::assertSame(ContainerPage::DEFAULT_LIMIT, ContainerPage::fromSource(
            self::organizationContainer(),
            $source
        )->limit());
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function clampedLimitProvider(): iterable
    {
        yield 'zero falls back to the default' => ['0', ContainerPage::DEFAULT_LIMIT];
        yield 'one is honoured' => ['1', 1];
        yield 'huge is clamped' => ['999999999', ContainerPage::MAX_LIMIT];
    }

    #[DataProvider('clampedLimitProvider')]
    public function testALimitIsClamped(string $requested, int $expected): void
    {
        self::assertSame($expected, ContainerPage::resolveLimit($requested));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedParameterProvider(): iterable
    {
        yield 'letters' => ['abc'];
        yield 'a signed number' => ['+5'];
        yield 'a decimal' => ['5.0'];
        yield 'hexadecimal' => ['0x1A'];
        yield 'a negative offset' => ['-1x'];
    }

    #[DataProvider('refusedParameterProvider')]
    public function testASyntacticallyBrokenBoundIsA400NotAClamp(string $raw): void
    {
        // A client that sent `limit=abc` has a bug and will keep sending it. Clamping
        // it would hide that; refusing it makes it visible.
        try {
            ContainerPage::resolveLimit($raw);
            self::fail(sprintf('Expected a 400 for the bound "%s".', $raw));
        } catch (DfcApiException $exception) {
            self::assertSame(400, $exception->error()->status());
            self::assertSame(ErrorCode::INVALID_REQUEST, $exception->error()->code());
        }
    }

    public function testANegativeOffsetIsClampedToZero(): void
    {
        $source = new ScriptedMembershipSource(['https://platform.example/dfc/v2/x/1']);

        self::assertSame(0, ContainerPage::resolveOffset('-1'));
        self::assertSame(0, ContainerPage::fromSource(
            self::organizationContainer(),
            $source,
            null,
            '-1'
        )->offset());
    }

    // -- Deduplication --------------------------------------------------------

    /**
     * A duplicate is refused, not silently dropped.
     *
     * Dropping it would make the page SHORTER than the limit while the source
     * believed it was full, which corrupts the offset arithmetic for every later
     * page: the client would re-see member 5 and never see member 6.
     */
    public function testADuplicateMemberIsRefusedRatherThanPublished(): void
    {
        try {
            $this->page([
                self::organizationIndex(),
                self::organizationIndex(),
            ]);
            self::fail('Expected the duplicate to be refused.');
        } catch (DfcApiException $exception) {
            self::assertSame(500, $exception->error()->status());
            self::assertSame(ErrorCode::MEMBERSHIP_INCONSISTENT, $exception->error()->code());
        }
    }

    public function testDistinctMembersAreFine(): void
    {
        $page = $this->page([
            self::organizationIndex(),
            self::organizationContainer() . 'catalogs',
        ]);

        self::assertCount(2, $page->members());
    }

    // -- Member shape ---------------------------------------------------------

    /**
     * A member is trimmed before validation, so a padded-but-valid URI is accepted;
     * anything that is not an absolute, fragment-free http(s) URI afterwards is
     * refused.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function memberShapeProvider(): iterable
    {
        yield 'a relative reference' => ['/organizations/1/index', false];
        yield 'a fragment' => ['https://platform.example/dfc/v2/x/index#me', false];
        yield 'a bare identifier' => ['index', false];
        yield 'an empty string' => ['', false];
        yield 'whitespace' => ['  https://platform.example/dfc/v2/x/index  ', true];
        yield 'a plain absolute URI' => ['https://platform.example/dfc/v2/x/index', true];
    }

    #[DataProvider('memberShapeProvider')]
    public function testAMemberMustBeAnAbsoluteFragmentFreeUri(string $member, bool $accepted): void
    {
        if (!$accepted) {
            $this->expectException(\InvalidArgumentException::class);
        }

        self::assertCount(1, $this->page([$member])->members());
    }

    public function testAMemberThatIsNotAStringIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->page([42]);
    }

    // -- The bound is an invariant of the value object too --------------------

    public function testAPageLargerThanItsLimitIsRefused(): void
    {
        $this->expectException(DfcApiException::class);

        $this->page([
            'https://platform.example/dfc/v2/x/1',
            'https://platform.example/dfc/v2/x/2',
        ], 1);
    }

    // -- Boundary behaviour ---------------------------------------------------

    public function testAnOffsetBeyondTheEndIsAnEmptyPageWithNoNextButStillAPrevious(): void
    {
        $page = $this->page([], 20, 100, 42);

        self::assertTrue($page->isEmpty());
        self::assertFalse($page->hasNextPage());
        self::assertNull($page->nextPageUri());
        // A client that overshot must be able to walk back, so `prev` is still there.
        self::assertTrue($page->hasPreviousPage());
        self::assertSame(80, $page->previousOffset());
        self::assertSame(
            ['<' . self::organizationContainer() . '?limit=20&offset=80>; rel="prev"'],
            array_map(static fn ($link): string => $link->toString(), $page->links())
        );
    }

    public function testAnEmptyCollectionHasNoNextPage(): void
    {
        $page = $this->page([], 20, 0, 0);

        self::assertFalse($page->hasNextPage());
        self::assertFalse($page->hasPreviousPage());
        self::assertTrue($page->isFirstPage());
        self::assertSame(0, $page->availableMembers());
        self::assertNull($page->previousPageUri());
    }

    public function testTheLastFullPageHasAPreviousButNoNext(): void
    {
        $page = $this->page(array_map(
            static fn (int $index): string => 'https://platform.example/dfc/v2/x/' . $index,
            range(1, 20)
        ), 20, 80, 100);

        self::assertFalse($page->hasNextPage());
        self::assertSame(60, $page->previousOffset());
        self::assertSame(
            self::organizationContainer() . '?limit=20&offset=60',
            $page->previousPageUri()
        );
    }

    public function testTheFinalPartialPageHasNoNext(): void
    {
        $page = $this->page([
            'https://platform.example/dfc/v2/x/1',
            'https://platform.example/dfc/v2/x/2',
        ], 20, 98, 100);

        self::assertFalse($page->hasNextPage());
        // One page back from the CURRENT offset, not to the nearest page boundary: a
        // client that walks `prev` repeatedly must see every member exactly once.
        self::assertSame(78, $page->previousOffset());
        self::assertSame(2, $page->availableMembers());
    }

    public function testASingleMemberCollectionHasNoNavigationAtAll(): void
    {
        $page = $this->page(['https://platform.example/dfc/v2/x/1'], 20, 0, 1);

        self::assertCount(1, $page->members());
        self::assertFalse($page->hasNextPage());
        self::assertFalse($page->hasPreviousPage());
        self::assertSame([], $page->links());
    }

    /**
     * The case that is easy to get wrong: a short page with a known total means the
     * collection CHANGED mid-read, not that it ended. Reporting "no more pages" here
     * silently truncates discovery — a member that exists is never listed and the
     * client cannot know.
     */
    public function testAShortPageWithAKnownTotalStillHasANextPage(): void
    {
        $page = $this->page(['https://platform.example/dfc/v2/x/1'], 20, 0, 50);

        self::assertCount(1, $page->members());
        self::assertTrue($page->hasNextPage());
        self::assertSame(1, $page->nextOffset());
    }

    public function testAnUnknownTotalInfersTheEndFromAFullPage(): void
    {
        $full = $this->page(array_map(
            static fn (int $index): string => 'https://platform.example/dfc/v2/x/' . $index,
            range(1, 20)
        ), 20, 0);

        self::assertNull($full->total());
        self::assertTrue($full->hasNextPage());

        $partial = $this->page(['https://platform.example/dfc/v2/x/1'], 20, 0);

        self::assertFalse($partial->hasNextPage());
    }

    public function testACountlessSourceIsASupportedDeploymentChoice(): void
    {
        $source = (new ScriptedMembershipSource(
            array_map(static fn (int $i): string => 'https://platform.example/dfc/v2/x/' . $i, range(1, 3))
        ))->withoutCount();

        // A partial page with no total is the only "there is nothing more" signal
        // available, so that is what it means.
        $page = ContainerPage::fromSource(self::organizationContainer(), $source, '5');

        self::assertNull($page->total());
        self::assertCount(3, $page->members());
        self::assertFalse($page->hasNextPage());
    }

    public function testAFullPageWithNoTotalIsTreatedAsHavingMore(): void
    {
        $source = (new ScriptedMembershipSource(
            array_map(static fn (int $i): string => 'https://platform.example/dfc/v2/x/' . $i, range(1, 5))
        ))->withoutCount();

        $page = ContainerPage::fromSource(self::organizationContainer(), $source, '5');

        self::assertNull($page->total());
        self::assertTrue(
            $page->hasNextPage(),
            'A full page with no total could be the end of the collection or exactly not. Walking one more '
            . 'page costs a round trip; stopping early loses members permanently.'
        );
    }

    public function testAvailableMembersNeverGoesNegative(): void
    {
        // A concurrent deletion can make the recorded total larger than reality.
        $page = $this->page([], 20, 500, 42);

        self::assertSame(0, $page->availableMembers());
        self::assertFalse($page->hasNextPage());
    }

    // -- Navigation -----------------------------------------------------------

    public function testPageUrisAreByteStableWithAFixedParameterOrder(): void
    {
        $page = $this->page([], 25, 50, 100);

        self::assertSame(self::organizationContainer() . '?limit=25&offset=50', $page->pageUri(50));
        self::assertSame(
            self::organizationContainer() . '?limit=25&offset=50',
            $page->nextPageUri()
        );
    }

    public function testANextOffsetStepsForwardByWhatWasActuallyReturned(): void
    {
        // Stepping by `limit` instead would skip members whenever a page came back
        // short, which is exactly what happens when the collection changes mid-read.
        $page = $this->page(['https://platform.example/dfc/v2/x/1'], 20, 0, 50);

        self::assertSame(1, $page->nextOffset());
    }

    public function testPagingLinksAreNextThenPrev(): void
    {
        $page = $this->page(array_map(
            static fn (int $index): string => 'https://platform.example/dfc/v2/x/' . $index,
            range(1, 20)
        ), 20, 20, 100);

        self::assertSame(['next', 'prev'], array_map(
            static fn ($link): string => $link->relations()[0],
            $page->links()
        ));
    }

    // -- Construction ---------------------------------------------------------

    public function testTheContainerUriKeepsExactlyOneTrailingSlash(): void
    {
        $withSlash = new ContainerPage('https://platform.example/dfc/v2/x/', [], 10, 0);
        $withoutSlash = new ContainerPage('https://platform.example/dfc/v2/x', [], 10, 0);

        self::assertSame($withSlash->containerUri(), $withoutSlash->containerUri());
    }

    public function testARelativeContainerUriIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('absolute http(s) URI ending in "/"');

        new ContainerPage('/dfc/v2/x/', [], 10, 0);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function refusedBoundProvider(): iterable
    {
        yield 'a zero limit' => [0];
        yield 'a negative limit' => [-1];
        yield 'a negative offset' => [0, -1];
        yield 'a negative total' => [10, 0, -5];
    }

    #[DataProvider('refusedBoundProvider')]
    public function testAnImpossibleBoundIsAProgrammingError(int $limit, int $offset = 0, ?int $total = null): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ContainerPage(ControllerFixtures::BASE_URI . '/x/', [], $limit, $offset, $total);
    }
}
