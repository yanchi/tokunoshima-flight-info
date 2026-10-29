<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\DTO\FlightBoardSnapshot;
use App\Application\Port\FlightBoardRepositoryInterface;
use App\Domain\Flight\Direction;
use App\Domain\Flight\FlightStatus;
use App\Domain\Flight\ServiceDate;
use App\Infrastructure\Persistence\NoDataFlightBoardRepository;
use App\Tests\Support\FictionalFlights;
use App\Tests\Support\InMemoryFlightBoardRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DomCrawler\Crawler;

final class BoardTodayTest extends WebTestCase
{
    private const OFFICIAL_SENTENCE = '最新の運航状況は、日本航空（JAL）公式サイトでご確認ください。';

    private KernelBrowser $client;
    private string $defaultTimezone;

    protected function setUp(): void
    {
        $this->defaultTimezone = date_default_timezone_get();
        // 2026-10-29 23:30 UTC is already 10-30 in JST.
        Clock::set(new MockClock('2026-10-29T23:30:00+00:00'));
        $this->client = static::createClient();
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        date_default_timezone_set($this->defaultTimezone);
        unset($_ENV['CONTACT_ADDRESS'], $_SERVER['CONTACT_ADDRESS']);
        parent::tearDown();
    }

    public function testDefaultsToDepartures(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.tabs [aria-current="page"]', '出発');
        self::assertSelectorTextSame('#board-heading', '徳之島発（出発便）');
    }

    public function testSwitchesToArrivals(): void
    {
        $this->client->request('GET', '/?direction=arrival');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.tabs [aria-current="page"]', '到着');
        self::assertSelectorTextSame('#board-heading', '徳之島着（到着便）');
    }

    public function testRejectsUnknownDirection(): void
    {
        $this->client->request('GET', '/?direction=sideways');

        self::assertResponseStatusCodeSame(404);
    }

    public function testShowsTodayInJst(): void
    {
        $this->client->request('GET', '/');

        self::assertSelectorTextSame('.board-date', '2026年10月30日（金）');
    }

    public function testDateDoesNotDependOnPhpDefaultTimezone(): void
    {
        // A zone east of JST used to shift a Y-m-d string back by one day.
        date_default_timezone_set('Pacific/Kiritimati');

        $this->client->request('GET', '/');

        self::assertSelectorTextSame('.board-date', '2026年10月30日（金）');
    }

    public function testWithoutDataShowsUnavailableMessage(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertSelectorTextContains('[role="status"]', '運航情報を取得できません');
        self::assertSelectorTextContains('[role="status"]', self::OFFICIAL_SENTENCE);
        self::assertSelectorNotExists('table.flights');
        self::assertSame(['未取得', 'なし'], $crawler->filter('.board-meta dd')->each(static fn (Crawler $dd) => $dd->text()));
    }

    public function testProductionWiringHasNoData(): void
    {
        self::assertInstanceOf(
            NoDataFlightBoardRepository::class,
            static::getContainer()->get(FlightBoardRepositoryInterface::class),
        );
    }

    public function testOmitsContactSentenceWhileContactAddressIsUnset(): void
    {
        $crawler = $this->client->request('GET', '/');

        $notice = $crawler->filter('.official-notice');
        self::assertStringContainsString(self::OFFICIAL_SENTENCE, $notice->text());
        self::assertStringContainsString('本サイトの表示内容について、航空会社への問い合わせはご遠慮ください。', $notice->text());
        self::assertStringNotContainsString('お問い合わせは', $notice->text());
        self::assertCount(0, $notice->filter('a'));
    }

    public function testShowsContactSentenceWhenContactAddressIsSet(): void
    {
        self::ensureKernelShutdown();
        $_ENV['CONTACT_ADDRESS'] = $_SERVER['CONTACT_ADDRESS'] = 'contact@example.test';
        $client = static::createClient();

        $crawler = $client->request('GET', '/');

        self::assertStringContainsString(
            '本サイトの表示内容について、航空会社への問い合わせはご遠慮ください。お問い合わせはcontact@example.testまで。',
            $crawler->filter('.official-notice')->text(),
        );
    }

    public function testTimetableOnlyFlightIsNotShownAsOnTime(): void
    {
        $this->useSnapshot(Direction::Departure, new FlightBoardSnapshot(
            [FictionalFlights::departure(FlightStatus::Scheduled, '2026-10-30T10:05:00+09:00')],
            new \DateTimeImmutable('2026-10-29T23:25:00+00:00'),
            'test source',
        ));

        $crawler = $this->client->request('GET', '/');

        self::assertSelectorNotExists('[role="status"]');
        self::assertSame(['ZZ1001', 'TKN → ZZZ', '10:05', '—なし', '運航状況未確認'], $this->firstRowCells($crawler));
        self::assertSelectorTextSame('.board-meta dd', '2026年10月30日 08:25（日本時間）');
    }

    public function testReadsArrivalsOnTheArrivalTab(): void
    {
        $this->useSnapshot(Direction::Arrival, new FlightBoardSnapshot(
            [FictionalFlights::arrival(FlightStatus::Delayed, '2026-10-30T11:00:00+09:00', '2026-10-30T11:25:00+09:00')],
            new \DateTimeImmutable('2026-10-29T23:25:00+00:00'),
            'test source',
        ));

        $crawler = $this->client->request('GET', '/?direction=arrival');

        self::assertSame(['ZZ1002', 'ZZZ → TKN', '11:00', '11:25', '遅延'], $this->firstRowCells($crawler));
        self::assertSelectorTextSame('table.flights thead th:nth-child(3)', '到着予定');
    }

    public function testShowsCancelledAndUnknownFlights(): void
    {
        $this->useSnapshot(Direction::Departure, new FlightBoardSnapshot(
            [
                FictionalFlights::departure(FlightStatus::Cancelled, '2026-10-30T10:05:00+09:00'),
                FictionalFlights::departure(FlightStatus::Unknown, '2026-10-30T15:00:00+09:00'),
            ],
            new \DateTimeImmutable('2026-10-29T23:25:00+00:00'),
            'test source',
        ));

        $crawler = $this->client->request('GET', '/');

        self::assertSame(['欠航', '状況不明'], $crawler->filter('table.flights tbody .status')->each(static fn (Crawler $s) => $s->text()));
    }

    public function testFetchedEmptyBoardDoesNotClaimThereAreNoFlights(): void
    {
        $this->useSnapshot(Direction::Departure, new FlightBoardSnapshot([], new \DateTimeImmutable('2026-10-29T23:25:00+00:00'), 'test source'));

        $this->client->request('GET', '/');

        self::assertSelectorTextSame('.board-empty', '表示できる便がありません。'.self::OFFICIAL_SENTENCE);
    }

    public function testStaleDataWithholdsObservedStatusAndEstimate(): void
    {
        $this->useSnapshot(Direction::Departure, new FlightBoardSnapshot(
            [FictionalFlights::departure(FlightStatus::Delayed, '2026-10-30T10:05:00+09:00', '2026-10-30T10:40:00+09:00')],
            new \DateTimeImmutable('2026-10-29T20:00:00+00:00'),
            'test source',
        ));

        $crawler = $this->client->request('GET', '/');

        self::assertSelectorTextContains('[role="status"]', '最終取得から時間が経過しています');
        self::assertSelectorTextContains('[role="status"]', self::OFFICIAL_SENTENCE);
        self::assertSame(['ZZ1001', 'TKN → ZZZ', '10:05', '—なし', '更新停止／要公式確認'], $this->firstRowCells($crawler));
        self::assertSelectorNotExists('.status--delayed');
    }

    private function useSnapshot(Direction $direction, FlightBoardSnapshot $snapshot): void
    {
        $repository = new InMemoryFlightBoardRepository();
        $repository->put(ServiceDate::fromString('2026-10-30'), $direction, $snapshot);
        static::getContainer()->set(FlightBoardRepositoryInterface::class, $repository);
    }

    /**
     * @return list<string>
     */
    private function firstRowCells(Crawler $crawler): array
    {
        return $crawler->filter('table.flights tbody tr')->first()->filter('th, td')->each(static fn (Crawler $c) => $c->text());
    }
}
