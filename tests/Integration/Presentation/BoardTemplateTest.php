<?php

declare(strict_types=1);

namespace App\Tests\Integration\Presentation;

use App\Application\DTO\BoardState;
use App\Application\DTO\DailyFlightBoard;
use App\Application\DTO\GuidedDateRange;
use App\Domain\Flight\Direction;
use App\Domain\Flight\ServiceDate;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

/**
 * Renders both board pages for every BoardState, so a new state cannot
 * fall into the published branch unnoticed. TEST ONLY data.
 */
final class BoardTemplateTest extends KernelTestCase
{
    private const SOURCE = 'テスト用の取得元';

    /**
     * @return iterable<string, array{BoardState, string, string}>
     */
    public static function states(): iterable
    {
        yield 'never fetched' => [BoardState::NeverFetched, '.message--error', '現在、本サイトでは便の情報を表示できません。'];
        yield 'read failed' => [BoardState::ReadFailed, '.message--error', '現在、本サイトでは便の情報を表示できません。'];
        yield 'suspended' => [BoardState::Suspended, '.message--error', '時刻表を一定期間取得できていないため、表示を停止しています。'];
        yield 'thresholds invalid' => [BoardState::ThresholdsInvalid, '.message--error', '時刻表の表示を停止しています。'];
        yield 'fetch time unknown' => [BoardState::FetchTimeUnknown, '.message--error', '時刻表の表示を停止しています。'];
        yield 'outside the published period' => [BoardState::OutsidePublishedPeriod, '.board-empty', '本サイトには表示できる便の情報がありません。'];
        yield 'published without flights' => [BoardState::Published, '.board-empty', '本サイトで取得した時刻表には、この日の便がありません。'];
    }

    public function testEveryStateIsCovered(): void
    {
        $covered = array_map(static fn (array $case): BoardState => $case[0], iterator_to_array(self::states()));

        foreach (BoardState::cases() as $state) {
            self::assertContains($state, $covered, $state->name);
        }
    }

    /**
     * @return iterable<string, array{string, BoardState, string, string}>
     */
    public static function pagesAndStates(): iterable
    {
        foreach (['board/today.html.twig', 'board/flights.html.twig'] as $template) {
            foreach (self::states() as $name => [$state, $selector, $text]) {
                yield $template.': '.$name => [$template, $state, $selector, $text];
            }
        }
    }

    #[DataProvider('pagesAndStates')]
    public function testEachStateHasItsOwnMessage(string $template, BoardState $state, string $selector, string $text): void
    {
        $crawler = new Crawler($this->render($template, self::board($state)));

        self::assertStringContainsString($text, $crawler->filter($selector)->text());
        self::assertCount(0, $crawler->filter('table.flights'));
        self::assertStringNotContainsString('「備考のある便」に表示しています', $crawler->filter('.board')->text());
        // Other days are offered only while the timetable may be shown.
        self::assertCount(self::offersDates($state) ? 1 : 0, $crawler->filter('.date-nav'));
    }

    private function render(string $template, DailyFlightBoard $board): string
    {
        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        return $twig->render($template, ['board' => $board]);
    }

    /**
     * As the use case builds them: a published day is guided, an outside
     * day is not (here the period starts the day after).
     */
    private static function guidedDates(BoardState $state): ?GuidedDateRange
    {
        return match ($state) {
            BoardState::Published => new GuidedDateRange(ServiceDate::fromString('2026-11-16'), ServiceDate::fromString('2026-11-30')),
            BoardState::OutsidePublishedPeriod => new GuidedDateRange(ServiceDate::fromString('2026-11-17'), ServiceDate::fromString('2026-11-30')),
            default => null,
        };
    }

    private static function offersDates(BoardState $state): bool
    {
        return BoardState::Published === $state || BoardState::OutsidePublishedPeriod === $state;
    }

    private static function board(BoardState $state): DailyFlightBoard
    {
        $nothingKnown = BoardState::NeverFetched === $state || BoardState::ReadFailed === $state;
        $hidesTime = $nothingKnown || BoardState::FetchTimeUnknown === $state;

        return new DailyFlightBoard(
            ServiceDate::fromString('2026-11-16'),
            Direction::Departure,
            $state,
            false,
            [],
            [],
            $hidesTime ? null : new \DateTimeImmutable('2026-11-16T02:00:00Z'),
            $nothingKnown ? null : self::SOURCE,
            self::guidedDates($state),
        );
    }
}
