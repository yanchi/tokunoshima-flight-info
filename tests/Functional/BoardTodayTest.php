<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\DTO\TimetableEntry;
use App\Application\Gate\WithdrawalCause;
use App\Application\Port\TimetableGateRepositoryInterface;
use App\Application\Port\TimetableRepositoryInterface;
use App\Application\UseCase\PurgeTimetableData;
use App\Domain\Flight\Direction;
use App\Domain\Flight\Weekday;
use App\Tests\Support\FictionalTimetable;
use App\Tests\Support\ResetsDatabaseSchema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DomCrawler\Crawler;

/**
 * TEST ONLY data: fictional flights (FictionalTimetable), stored in the
 * test database through the production repositories.
 */
final class BoardTodayTest extends WebTestCase
{
    use ResetsDatabaseSchema;

    private const OFFICIAL_SENTENCE = '最新の運航状況は、日本航空（JAL）公式サイトでご確認ください。';

    private KernelBrowser $client;
    private string $defaultTimezone;

    protected function setUp(): void
    {
        $this->defaultTimezone = date_default_timezone_get();
        // 2026-11-15 23:30 UTC is already Monday 11-16 in JST.
        Clock::set(new MockClock('2026-11-15T23:30:00+00:00'));
        $this->client = static::createClient();
        self::resetDatabaseSchema(static::getContainer());
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

    public function testDoesNotLoadHotReloadScriptsOutsideDev(): void
    {
        $this->client->request('GET', '/', server: ['FRANKENPHP_HOT_RELOAD' => 'https://hot-reload.example.invalid']);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('meta[name="frankenphp-hot-reload:url"]');
        self::assertSelectorNotExists('script[src*="cdn.jsdelivr.net"]');
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

        self::assertResponseStatusCodeSame(400);
    }

    public function testShowsTodayInJst(): void
    {
        $this->client->request('GET', '/');

        self::assertSelectorTextSame('.board-date', '2026年11月16日（月）');
    }

    public function testDateDoesNotDependOnPhpDefaultTimezone(): void
    {
        // A zone east of JST used to shift a Y-m-d string back by one day.
        date_default_timezone_set('Pacific/Kiritimati');

        $this->client->request('GET', '/');

        self::assertSelectorTextSame('.board-date', '2026年11月16日（月）');
    }

    public function testWithoutDataShowsUnavailableMessage(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertSelectorTextContains('[role="status"]', '運航情報を取得できません');
        self::assertSelectorTextContains('[role="status"]', self::OFFICIAL_SENTENCE);
        self::assertSelectorNotExists('table.flights');
        self::assertSame(['未取得', 'なし'], $crawler->filter('.board-meta dd')->each(static fn (Crawler $dd) => $dd->text()));
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

    public function testFetchedButNotPublishedShowsNoFlights(): void
    {
        $this->storeDepartures('2026-11-15T23:00:00Z', [FictionalTimetable::departure('ZZ1001', Weekday::Monday, '09:00')]);

        $crawler = $this->client->request('GET', '/');

        self::assertSelectorNotExists('[role="status"]');
        self::assertSelectorNotExists('table.flights');
        self::assertSelectorTextSame('.board-empty', '本サイトには表示できる便の情報がありません。'.self::OFFICIAL_SENTENCE);
        self::assertSame(['2026年11月16日 08:00（日本時間）', '公共交通オープンデータセンター'], $this->meta($crawler));
    }

    public function testPublishedTimetableIsShownAsUnconfirmed(): void
    {
        $this->storeDepartures('2026-11-15T23:00:00Z', [
            FictionalTimetable::departure('ZZ1005', Weekday::Monday, '15:00'),
            FictionalTimetable::departure('ZZ1001', Weekday::Monday, '09:05'),
            FictionalTimetable::departure('ZZ1003', Weekday::Tuesday, '10:00'),
        ]);
        $this->publish();

        $crawler = $this->client->request('GET', '/');

        self::assertSelectorNotExists('[role="status"]');
        self::assertSame([
            ['ZZ1001', 'TKN → ZZZ', '09:05', '運航状況未確認'],
            ['ZZ1005', 'TKN → ZZZ', '15:00', '運航状況未確認'],
        ], $this->rows($crawler, 'table.flights'));
        self::assertSelectorNotExists('.noted-flights');
        self::assertSelectorExists('table.flights time[datetime="2026-11-16T09:05:00+09:00"]');
    }

    public function testArrivalTabShowsArrivals(): void
    {
        $this->store(Direction::Arrival, '2026-11-15T23:00:00Z', [FictionalTimetable::arrival('ZZ1002', Weekday::Monday, '11:10')]);
        $this->publish();

        $crawler = $this->client->request('GET', '/?direction=arrival');

        self::assertSame([['ZZ1002', 'ZZZ → TKN', '11:10', '運航状況未確認']], $this->rows($crawler, 'table.flights'));
        self::assertSelectorTextSame('table.flights thead th:nth-child(3)', '到着予定');
    }

    public function testNotedFlightsAreListedApartWithTheRawNote(): void
    {
        $this->storeDepartures('2026-11-15T23:00:00Z', [
            FictionalTimetable::departure('ZZ1001', Weekday::Monday, '09:00'),
            FictionalTimetable::departure('ZZ1003', Weekday::Monday, '15:00', '11/16-23・テスト用の<備考>'),
        ]);
        $this->publish();

        $crawler = $this->client->request('GET', '/');

        $main = $crawler->filter('.board > .table-wrap table.flights');
        self::assertSame([['ZZ1001', 'TKN → ZZZ', '09:00', '運航状況未確認']], $this->rows($main));
        self::assertSelectorTextSame('#noted-heading', '備考のある便');
        self::assertSame(
            [['ZZ1003', 'TKN → ZZZ', '備考参照', '11/16-23・テスト用の<備考>', '運航状況未確認']],
            $this->rows($crawler, '.noted-flights table'),
        );
        // The timetable time of a noted flight is not on the page.
        self::assertStringNotContainsString('15:00', $crawler->filter('.board')->text());
    }

    public function testDayWithOnlyNotedFlightsPointsToTheNotedList(): void
    {
        $this->storeDepartures('2026-11-15T23:00:00Z', [FictionalTimetable::departure('ZZ1003', Weekday::Monday, '15:00', 'テスト用の備考')]);
        $this->publish();

        $this->client->request('GET', '/');

        self::assertSelectorTextSame('.board-empty', 'この日の便は、すべて下の「備考のある便」に表示しています。');
        self::assertSelectorExists('.noted-flights table');
    }

    public function testPublishedDayWithoutFlightsSaysSo(): void
    {
        $this->storeDepartures('2026-11-15T23:00:00Z', [FictionalTimetable::departure('ZZ1003', Weekday::Tuesday, '10:00')]);
        $this->publish();

        $this->client->request('GET', '/');

        self::assertSelectorTextSame('.board-empty', '本サイトで取得した時刻表には、この日の便がありません。'.self::OFFICIAL_SENTENCE);
        self::assertSelectorNotExists('table.flights');
    }

    public function testOverdueFetchWarnsButStillShowsTheTimetable(): void
    {
        $this->storeDepartures('2026-11-12T23:29:59Z', [FictionalTimetable::departure('ZZ1001', Weekday::Monday, '09:00')]);
        $this->publish();

        $crawler = $this->client->request('GET', '/');

        self::assertSelectorTextContains('.message--warning', '最終取得から時間が経過しています');
        self::assertSelectorTextContains('.message--warning', '時刻表をしばらく取得できていません。'.self::OFFICIAL_SENTENCE);
        self::assertCount(1, $this->rows($crawler, 'table.flights'));
    }

    public function testSuspendedDirectionShowsTheLastFetchButNoFlights(): void
    {
        $this->storeDepartures('2026-11-08T23:29:59Z', [FictionalTimetable::departure('ZZ1001', Weekday::Monday, '09:00')]);
        $this->publish();

        $crawler = $this->client->request('GET', '/');

        self::assertSelectorTextContains('.message--error', '運航情報を取得できません');
        self::assertSelectorTextContains('.message--error', '時刻表を一定期間取得できていないため、表示を停止しています。'.self::OFFICIAL_SENTENCE);
        self::assertSelectorNotExists('table.flights');
        self::assertSelectorNotExists('.board-empty');
        self::assertSame(['2026年11月9日 08:29（日本時間）', '公共交通オープンデータセンター'], $this->meta($crawler));
    }

    public function testOnlyTheStaleDirectionIsSuspended(): void
    {
        $this->storeDepartures('2026-11-08T23:29:59Z', [FictionalTimetable::departure('ZZ1001', Weekday::Monday, '09:00')]);
        $this->store(Direction::Arrival, '2026-11-15T23:00:00Z', [FictionalTimetable::arrival('ZZ1002', Weekday::Monday, '11:10')]);
        $this->publish();

        $this->client->request('GET', '/?direction=arrival');

        self::assertSelectorNotExists('[role="status"]');
        self::assertSelectorExists('table.flights');
    }

    public function testFetchTimeInTheFutureIsNotShown(): void
    {
        $this->storeDepartures('2026-11-15T23:00:00Z', [FictionalTimetable::departure('ZZ1001', Weekday::Monday, '09:00')]);
        $this->publish();
        // The clock goes back after the save, e.g. a corrected system clock.
        Clock::set(new MockClock('2026-11-15T22:00:00+00:00'));

        $crawler = $this->client->request('GET', '/');

        self::assertSelectorTextContains('.message--error', '時刻表の表示を停止しています。'.self::OFFICIAL_SENTENCE);
        // The fetch may be recent, so the text must not blame it.
        self::assertSelectorTextNotContains('.message--error', '一定期間取得できていない');
        self::assertSelectorNotExists('table.flights');
        self::assertSame(['取得日時を確認できません', '公共交通オープンデータセンター'], $this->meta($crawler));
    }

    public function testMissingTablesShowAnUnavailableBoardInsteadOfAnError(): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        new SchemaTool($entityManager)->dropSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.message--error', '運航情報を取得できません');
        self::assertSelectorTextContains('.message--error', '現在、本サイトでは便の情報を表示できません。'.self::OFFICIAL_SENTENCE);
        self::assertSame(['確認できません', '確認できません'], $this->meta($crawler));
    }

    public function testAfterThePurgeTheBoardIsAsIfNeverFetched(): void
    {
        $this->storeDepartures('2026-11-15T23:00:00Z', [FictionalTimetable::departure('ZZ1001', Weekday::Monday, '09:05')]);
        $this->store(Direction::Arrival, '2026-11-15T23:00:00Z', [FictionalTimetable::arrival('ZZ1002', Weekday::Monday, '10:30')]);
        $this->publish();
        foreach (['/', '/?direction=arrival'] as $url) {
            $this->client->request('GET', $url);
            self::assertSelectorExists('table.flights', $url);
        }

        static::getContainer()->get(PurgeTimetableData::class)->execute();

        foreach (['/', '/?direction=arrival'] as $url) {
            $crawler = $this->client->request('GET', $url);
            self::assertSelectorTextContains('[role="status"]', '運航情報を取得できません', $url);
            self::assertSelectorNotExists('table.flights', $url);
            self::assertSame(['未取得', 'なし'], $this->meta($crawler), $url);
        }
    }

    public function testPublishedBoardHasNoEstimatedTimeColumn(): void
    {
        $this->storeDepartures('2026-11-15T23:00:00Z', [FictionalTimetable::departure('ZZ1001', Weekday::Monday, '09:00')]);
        $this->publish();

        $crawler = $this->client->request('GET', '/');

        self::assertSame(['便名', '区間', '出発予定', '運航状況'], $crawler->filter('table.flights thead th')->each(static fn (Crawler $th): string => $th->text()));
    }

    public function testWithdrawnDirectionIsNotShown(): void
    {
        $this->storeDepartures('2026-11-15T23:00:00Z', [FictionalTimetable::departure('ZZ1001', Weekday::Monday, '09:00')]);
        $version = $this->publish();
        $this->gate()->withdraw($version, Direction::Departure, WithdrawalCause::Manual, new \DateTimeImmutable('2026-11-15T23:10:00Z'));

        $this->client->request('GET', '/');

        self::assertSelectorTextSame('.board-empty', '本サイトには表示できる便の情報がありません。'.self::OFFICIAL_SENTENCE);
        self::assertSelectorNotExists('table.flights');
    }

    /**
     * @param list<TimetableEntry> $entries
     */
    private function storeDepartures(string $requestStartedAt, array $entries): void
    {
        $this->store(Direction::Departure, $requestStartedAt, $entries);
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

    private function publish(): int
    {
        return $this->gate()->addVersion(FictionalTimetable::settings('2026-11-09', '2026-11-30'), new \DateTimeImmutable('2026-11-15T23:05:00Z'))->id;
    }

    private function gate(): TimetableGateRepositoryInterface
    {
        /** @var TimetableGateRepositoryInterface $gate */
        $gate = static::getContainer()->get('test.timetable_gate_repository');

        return $gate;
    }

    /**
     * @return list<string>
     */
    private function meta(Crawler $crawler): array
    {
        return $crawler->filter('.board-meta dd')->each(static fn (Crawler $dd): string => $dd->text());
    }

    /**
     * @return list<list<string>>
     */
    private function rows(Crawler $crawler, ?string $table = null): array
    {
        $scope = null === $table ? $crawler : $crawler->filter($table);

        return $scope->filter('tbody tr')->each(
            static fn (Crawler $row): array => $row->filter('th, td')->each(static fn (Crawler $c): string => $c->text()),
        );
    }
}
