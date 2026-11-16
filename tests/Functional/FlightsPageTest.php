<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\DTO\TimetableEntry;
use App\Application\Gate\WithdrawalCause;
use App\Application\Port\TimetableGateRepositoryInterface;
use App\Application\Port\TimetableRepositoryInterface;
use App\Domain\Flight\Direction;
use App\Domain\Flight\Weekday;
use App\Tests\Support\FictionalTimetable;
use App\Tests\Support\ResetsDatabaseSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DomCrawler\Crawler;

/**
 * `/flights?date=&direction=`. TEST ONLY data: fictional flights
 * (FictionalTimetable), stored in the test database through the
 * production repositories. Today is Monday 2026-11-16 (JST).
 */
final class FlightsPageTest extends WebTestCase
{
    use ResetsDatabaseSchema;

    private const OFFICIAL_SENTENCE = '最新の運航状況は、日本航空（JAL）公式サイトでご確認ください。';
    private const OUTSIDE = '本サイトには表示できる便の情報がありません。'.self::OFFICIAL_SENTENCE;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        // 2026-11-15 23:30 UTC is already Monday 11-16 in JST.
        Clock::set(new MockClock('2026-11-15T23:30:00+00:00'));
        $this->client = static::createClient();
        self::resetDatabaseSchema(static::getContainer());
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testShowsTheRequestedDay(): void
    {
        $this->storeBoth();

        $crawler = $this->client->request('GET', '/flights?date=2026-11-17');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', '日付別の発着便');
        self::assertSelectorTextSame('.board-date', '2026年11月17日（火）');
        self::assertSelectorTextSame('.tabs [aria-current="page"]', '出発');
        self::assertSame([['ZZ1003', 'TKN → ZZZ', '10:00', '運航状況未確認']], $this->rows($crawler));
        self::assertSelectorExists('table.flights time[datetime="2026-11-17T10:00:00+09:00"]');
        self::assertPageTitleSame('11月17日の出発便 | 徳之島空港 発着案内（非公式）');
    }

    public function testArrivalsOfTheRequestedDay(): void
    {
        $this->storeBoth();

        $crawler = $this->client->request('GET', '/flights?date=2026-11-17&direction=arrival');

        self::assertSelectorTextSame('.tabs [aria-current="page"]', '到着');
        self::assertSame([['ZZ1004', 'ZZZ → TKN', '12:10', '運航状況未確認']], $this->rows($crawler));
    }

    public function testTabsKeepTheDate(): void
    {
        $this->storeBoth();

        $crawler = $this->client->request('GET', '/flights?date=2026-11-17');

        self::assertSame(
            ['/flights?date=2026-11-17', '/flights?date=2026-11-17&direction=arrival'],
            $crawler->filter('.tabs a')->each(static fn (Crawler $a): ?string => $a->attr('href')),
        );
    }

    public function testWithoutADateShowsToday(): void
    {
        $this->storeBoth();

        $crawler = $this->client->request('GET', '/flights');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.board-date', '2026年11月16日（月）');
        self::assertSame([['ZZ1001', 'TKN → ZZZ', '09:00', '運航状況未確認']], $this->rows($crawler));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badQueries(): iterable
    {
        yield 'not a date' => ['date=tomorrow'];
        yield 'overflowing date' => ['date=2026-02-30'];
        yield 'other format' => ['date=2026/11/17'];
        yield 'empty date' => ['date='];
        yield 'date as an array' => ['date[]=2026-11-17'];
        yield 'unknown direction' => ['date=2026-11-17&direction=sideways'];
        yield 'empty direction' => ['date=2026-11-17&direction='];
        yield 'capitalised direction' => ['date=2026-11-17&direction=Arrival'];
        yield 'direction as an array' => ['direction[]=arrival'];
    }

    #[DataProvider('badQueries')]
    public function testMalformedQueryIsABadRequest(string $query): void
    {
        $this->storeBoth();

        $this->client->request('GET', '/flights?'.$query);

        self::assertResponseStatusCodeSame(400);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function daysNotShown(): iterable
    {
        yield 'yesterday, inside the compared period' => ['2026-11-15'];
        yield 'after the compared period' => ['2026-12-01'];
        yield 'far in the future' => ['2030-01-01'];
    }

    #[DataProvider('daysNotShown')]
    public function testDaysOutsideTheGuidedDatesShowNoFlights(string $day): void
    {
        $this->storeBoth();

        $this->client->request('GET', '/flights?date='.$day);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.board-empty', self::OUTSIDE);
        self::assertSelectorNotExists('table.flights');
        self::assertSelectorNotExists('.noted-flights');
    }

    public function testDateNavigationStaysInsideTheGuidedDates(): void
    {
        $this->storeBoth();

        $crawler = $this->client->request('GET', '/flights?date=2026-11-20&direction=arrival');

        self::assertSame(
            ['/flights?date=2026-11-19&direction=arrival', '/flights?date=2026-11-21&direction=arrival'],
            $crawler->filter('.date-nav-links a')->each(static fn (Crawler $a): ?string => $a->attr('href')),
        );
        $input = $crawler->filter('.date-form input[type="date"]');
        self::assertSame(['2026-11-20', '2026-11-16', '2026-11-30'], [$input->attr('value'), $input->attr('min'), $input->attr('max')]);
        self::assertSame('arrival', $crawler->filter('.date-form input[name="direction"]')->attr('value'));
        self::assertSelectorTextSame('.date-nav-range', '到着便を表示できる日付：2026年11月16日〜2026年11月30日');
    }

    public function testNoNextDayOnTheLastGuidedDay(): void
    {
        $this->storeBoth();

        $crawler = $this->client->request('GET', '/flights?date=2026-11-30');

        self::assertSame(['/flights?date=2026-11-29'], $crawler->filter('.date-nav-links a')->each(static fn (Crawler $a): ?string => $a->attr('href')));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function outsideDaysNextToTheGuidedDates(): iterable
    {
        yield 'yesterday points to today' => ['2026-11-15', ['/flights?date=2026-11-16']];
        yield 'day after the period points back to its last day' => ['2026-12-01', ['/flights?date=2026-11-30']];
    }

    /**
     * @param list<string> $links
     */
    #[DataProvider('outsideDaysNextToTheGuidedDates')]
    public function testOutsideDayNextToTheGuidedDatesLinksIntoThem(string $day, array $links): void
    {
        $this->storeBoth();

        $crawler = $this->client->request('GET', '/flights?date='.$day);

        self::assertSelectorTextSame('.board-empty', self::OUTSIDE);
        self::assertSame($links, $crawler->filter('.date-nav-links a')->each(static fn (Crawler $a): ?string => $a->attr('href')));
    }

    public function testSingleGuidedDayOffersOnlyTheDateInput(): void
    {
        $this->store(Direction::Departure, '2026-11-15T23:00:00Z', [FictionalTimetable::departure('ZZ1001', Weekday::Monday, '09:00')]);
        /** @var TimetableGateRepositoryInterface $gate */
        $gate = static::getContainer()->get('test.timetable_gate_repository');
        $gate->addVersion(FictionalTimetable::settings('2026-11-09', '2026-11-16'), new \DateTimeImmutable('2026-11-15T23:05:00Z'));

        $crawler = $this->client->request('GET', '/flights?date=2026-11-16');

        self::assertCount(1, $this->rows($crawler));
        self::assertSelectorNotExists('.date-nav-links');
        $input = $crawler->filter('.date-form input[type="date"]');
        self::assertSame(['2026-11-16', '2026-11-16', '2026-11-16'], [$input->attr('value'), $input->attr('min'), $input->attr('max')]);
        self::assertSelectorTextSame('.date-nav-range', '出発便を表示できる日付：2026年11月16日〜2026年11月16日');
    }

    public function testOutsideDayStillOffersTheGuidedDates(): void
    {
        $this->storeBoth();

        $crawler = $this->client->request('GET', '/flights?date=2026-12-24');

        self::assertSelectorNotExists('.date-nav-links');
        self::assertSame('2026-11-16', $crawler->filter('.date-form input[type="date"]')->attr('value'));
    }

    public function testDateFormSubmitsToTheFlightsPage(): void
    {
        $this->storeBoth();
        $crawler = $this->client->request('GET', '/');

        $form = $crawler->filter('.date-form')->form(['date' => '2026-11-17']);
        $crawler = $this->client->submit($form);

        self::assertSame('/flights?date=2026-11-17', $this->client->getRequest()->getRequestUri());
        self::assertSame([['ZZ1003', 'TKN → ZZZ', '10:00', '運航状況未確認']], $this->rows($crawler));
    }

    public function testTodayOffersTheNextDayOnly(): void
    {
        $this->storeBoth();

        $crawler = $this->client->request('GET', '/');

        self::assertSame(['/flights?date=2026-11-17'], $crawler->filter('.date-nav-links a')->each(static fn (Crawler $a): ?string => $a->attr('href')));
    }

    public function testNoDatesAreOfferedWhileNothingIsPublished(): void
    {
        $this->store(Direction::Departure, '2026-11-15T23:00:00Z', [FictionalTimetable::departure('ZZ1003', Weekday::Tuesday, '10:00')]);

        $this->client->request('GET', '/flights?date=2026-11-17');

        self::assertSelectorTextSame('.board-empty', self::OUTSIDE);
        self::assertSelectorNotExists('.date-nav');
    }

    public function testNoDatesAreOfferedWhileSuspended(): void
    {
        $this->store(Direction::Departure, '2026-11-08T23:29:59Z', [FictionalTimetable::departure('ZZ1003', Weekday::Tuesday, '10:00')]);
        $this->publish();

        $this->client->request('GET', '/flights?date=2026-11-17');

        self::assertSelectorTextContains('.message--error', '時刻表を一定期間取得できていないため、表示を停止しています。'.self::OFFICIAL_SENTENCE);
        self::assertSelectorNotExists('table.flights');
        self::assertSelectorNotExists('.date-nav');
    }

    public function testNotedFlightsOfTheRequestedDayAreListedApart(): void
    {
        $this->store(Direction::Departure, '2026-11-15T23:00:00Z', [
            FictionalTimetable::departure('ZZ1003', Weekday::Tuesday, '10:00'),
            FictionalTimetable::departure('ZZ1005', Weekday::Tuesday, '15:00', '11/24のみ・テスト用の備考'),
        ]);
        $this->publish();

        $crawler = $this->client->request('GET', '/flights?date=2026-11-17');

        self::assertSame([['ZZ1003', 'TKN → ZZZ', '10:00', '運航状況未確認']], $this->rows($crawler, '.board > .table-wrap table.flights'));
        self::assertSame([['ZZ1005', 'TKN → ZZZ', '備考参照', '11/24のみ・テスト用の備考', '運航状況未確認']], $this->rows($crawler, '.noted-flights table'));
    }

    public function testEachDirectionGuidesItsOwnDates(): void
    {
        $this->storeBoth();
        /** @var TimetableGateRepositoryInterface $gate */
        $gate = static::getContainer()->get('test.timetable_gate_repository');
        $version = $gate->findCurrentState()?->version->id;
        self::assertNotNull($version);
        $gate->withdraw($version, Direction::Arrival, WithdrawalCause::Manual, new \DateTimeImmutable('2026-11-15T23:10:00Z'));

        $crawler = $this->client->request('GET', '/flights?date=2026-11-17');
        self::assertCount(1, $this->rows($crawler));
        self::assertSame('2026-11-30', $crawler->filter('.date-form input[type="date"]')->attr('max'));

        // The arrival tab keeps the date, shows nothing and offers no date.
        $crawler = $this->client->click($crawler->filter('.tabs a')->eq(1)->link());
        self::assertSame('/flights?date=2026-11-17&direction=arrival', $this->client->getRequest()->getRequestUri());
        self::assertSelectorTextSame('.board-empty', self::OUTSIDE);
        self::assertSelectorNotExists('table.flights');
        self::assertSelectorNotExists('.date-nav');
    }

    public function testOverdueFetchWarnsOnAGuidedDay(): void
    {
        $this->store(Direction::Departure, '2026-11-12T23:29:59Z', [FictionalTimetable::departure('ZZ1003', Weekday::Tuesday, '10:00')]);
        $this->publish();

        $this->client->request('GET', '/flights?date=2026-11-17');

        self::assertSelectorTextContains('.message--warning', '最終取得から時間が経過しています');
        self::assertSelectorExists('table.flights');
    }

    public function testOverdueFetchDoesNotWarnOnAnOutsideDay(): void
    {
        $this->store(Direction::Departure, '2026-11-12T23:29:59Z', [FictionalTimetable::departure('ZZ1003', Weekday::Tuesday, '10:00')]);
        $this->publish();

        $this->client->request('GET', '/flights?date=2026-12-01');

        self::assertSelectorNotExists('.message--warning');
        self::assertSelectorTextSame('.board-empty', self::OUTSIDE);
    }

    public function testFooterLinkToTodayIsNotMarkedCurrent(): void
    {
        $this->client->request('GET', '/flights');

        self::assertSelectorNotExists('.footer-nav [aria-current="page"]');
        self::assertSelectorTextContains('.back-link', '本日の発着便に戻る');
    }

    /**
     * Departures 09:00 Monday and 10:00 Tuesday, arrivals 11:10 Monday and
     * 12:10 Tuesday; published 2026-11-09 to 2026-11-30.
     */
    private function storeBoth(): void
    {
        $this->store(Direction::Departure, '2026-11-15T23:00:00Z', [
            FictionalTimetable::departure('ZZ1001', Weekday::Monday, '09:00'),
            FictionalTimetable::departure('ZZ1003', Weekday::Tuesday, '10:00'),
        ]);
        $this->store(Direction::Arrival, '2026-11-15T23:00:00Z', [
            FictionalTimetable::arrival('ZZ1002', Weekday::Monday, '11:10'),
            FictionalTimetable::arrival('ZZ1004', Weekday::Tuesday, '12:10'),
        ]);
        $this->publish();
    }

    /**
     * @param list<TimetableEntry> $entries
     */
    private function store(Direction $direction, string $requestStartedAt, array $entries): void
    {
        /** @var TimetableRepositoryInterface $repository */
        $repository = static::getContainer()->get('test.timetable_repository');
        self::assertTrue($repository->save(FictionalTimetable::fetch($direction, $requestStartedAt, $entries))->outcome->replaced());
    }

    private function publish(): void
    {
        /** @var TimetableGateRepositoryInterface $gate */
        $gate = static::getContainer()->get('test.timetable_gate_repository');
        $gate->addVersion(FictionalTimetable::settings('2026-11-09', '2026-11-30'), new \DateTimeImmutable('2026-11-15T23:05:00Z'));
    }

    /**
     * @return list<list<string>>
     */
    private function rows(Crawler $crawler, string $table = 'table.flights'): array
    {
        return $crawler->filter($table)->filter('tbody tr')->each(
            static fn (Crawler $row): array => $row->filter('th, td')->each(static fn (Crawler $c): string => $c->text()),
        );
    }
}
