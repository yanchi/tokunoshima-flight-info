<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class InfoPagesTest extends WebTestCase
{
    private const OFFICIAL_SENTENCE = '最新の運航状況は、日本航空（JAL）公式サイトでご確認ください。';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pages(): iterable
    {
        yield 'about' => ['/about', 'このサイトについて'];
        yield 'sources' => ['/sources', 'データの出典'];
    }

    #[DataProvider('pages')]
    public function testShowsPage(string $path, string $heading): void
    {
        $this->client->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', $heading);
        self::assertPageTitleContains($heading);
    }

    #[DataProvider('pages')]
    public function testShowsOfficialNoticeWithoutLinkingToJal(string $path, string $heading): void
    {
        $crawler = $this->client->request('GET', $path);

        self::assertSelectorTextContains('.official-notice', self::OFFICIAL_SENTENCE);
        self::assertStringNotContainsString('jal.co.jp', $crawler->html());
        self::assertStringNotContainsString('jal.com', $crawler->html());
    }

    #[DataProvider('pages')]
    public function testLinksBackToTodaysBoard(string $path, string $heading): void
    {
        $this->client->request('GET', $path);

        self::assertSelectorTextSame('main .back-link a[href="/"]', '← 本日の発着便に戻る');
    }

    public function testTodaysBoardHasNoBackLink(): void
    {
        $this->client->request('GET', '/');

        self::assertSelectorNotExists('.back-link');
    }

    public function testFooterLinksToInfoPages(): void
    {
        $this->client->request('GET', '/');

        self::assertSelectorExists('.footer-nav a[href="/"]');
        self::assertSelectorExists('.footer-nav a[href="/about"]');
        self::assertSelectorExists('.footer-nav a[href="/sources"]');
    }

    public function testHeaderLinksToSiblingFerrySite(): void
    {
        $this->client->request('GET', '/');

        self::assertSelectorTextSame('.site-header a[href="https://ship.isl-mentor.com/"]', 'フェリーの運航情報はこちら');
    }

    public function testSourcesDoesNotClaimOdptDataWhileNoSourceIsEnabled(): void
    {
        $crawler = $this->client->request('GET', '/sources');

        self::assertSelectorTextContains('main', '現在、本サイトでは便の情報を表示していません。');
        self::assertStringNotContainsString('公共交通オープンデータセンター', $crawler->filter('main')->text());
    }
}
