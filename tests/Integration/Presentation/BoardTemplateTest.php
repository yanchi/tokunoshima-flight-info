<?php

declare(strict_types=1);

namespace App\Tests\Integration\Presentation;

use App\Application\DTO\BoardState;
use App\Application\DTO\DailyFlightBoard;
use App\Domain\Flight\Direction;
use App\Domain\Flight\ServiceDate;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

/**
 * Renders the board template for every BoardState, so a new state cannot
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

    #[DataProvider('states')]
    public function testEachStateHasItsOwnMessage(BoardState $state, string $selector, string $text): void
    {
        $crawler = new Crawler($this->render(self::board($state)));

        self::assertStringContainsString($text, $crawler->filter($selector)->text());
        self::assertCount(0, $crawler->filter('table.flights'));
        self::assertStringNotContainsString('「備考のある便」に表示しています', $crawler->filter('.board')->text());
    }

    private function render(DailyFlightBoard $board): string
    {
        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        return $twig->render('board/today.html.twig', ['board' => $board]);
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
        );
    }
}
