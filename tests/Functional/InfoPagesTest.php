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

    protected function tearDown(): void
    {
        unset($_ENV['CONTACT_ADDRESS'], $_SERVER['CONTACT_ADDRESS']);
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pagesWithHeadings(): iterable
    {
        yield 'about' => ['/about', 'このサイトについて'];
        yield 'sources' => ['/sources', 'データの出典'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pages(): iterable
    {
        yield 'about' => ['/about'];
        yield 'sources' => ['/sources'];
    }

    #[DataProvider('pagesWithHeadings')]
    public function testShowsPage(string $path, string $heading): void
    {
        $this->client->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', $heading);
        self::assertPageTitleContains($heading);
    }

    #[DataProvider('pages')]
    public function testShowsOfficialNoticeWithoutLinkingToJal(string $path): void
    {
        $crawler = $this->client->request('GET', $path);

        self::assertSelectorTextContains('.official-notice', self::OFFICIAL_SENTENCE);
        self::assertStringNotContainsString('jal.co.jp', $crawler->html());
        self::assertStringNotContainsString('jal.com', $crawler->html());
    }

    #[DataProvider('pages')]
    public function testLinksBackToTodaysBoard(string $path): void
    {
        $this->client->request('GET', $path);

        self::assertSelectorTextSame('main .back-link a[href="/"]', '← 本日の発着便に戻る');
        self::assertSelectorTextSame('main .back-link a [aria-hidden="true"]', '←');
    }

    public function testTodaysBoardHasNoBackLink(): void
    {
        $this->client->request('GET', '/');

        self::assertSelectorNotExists('.back-link');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function allPages(): iterable
    {
        yield 'board' => ['/'];
        yield 'about' => ['/about'];
        yield 'sources' => ['/sources'];
    }

    #[DataProvider('allPages')]
    public function testFooterLinksToEveryPageAndMarksTheCurrentOne(string $path): void
    {
        $this->client->request('GET', $path);

        self::assertSelectorExists('.footer-nav a[href="/"]');
        self::assertSelectorExists('.footer-nav a[href="/about"]');
        self::assertSelectorExists('.footer-nav a[href="/sources"]');
        self::assertSelectorCount(1, '.footer-nav a[aria-current="page"]');
        self::assertSelectorExists(\sprintf('.footer-nav a[href="%s"][aria-current="page"]', $path));
    }

    public function testShowsContactAsPendingWhileContactAddressIsUnset(): void
    {
        $this->client->request('GET', '/about');

        self::assertSelectorTextSame('.contact-address', '準備中');
    }

    #[DataProvider('pages')]
    public function testShowsContactAddressWhenSet(string $path): void
    {
        self::ensureKernelShutdown();
        $_ENV['CONTACT_ADDRESS'] = $_SERVER['CONTACT_ADDRESS'] = 'contact@example.test';
        $client = static::createClient();

        $client->request('GET', $path);

        self::assertSelectorTextContains('.official-notice', 'お問い合わせはcontact@example.testまで。');
        if ('/about' === $path) {
            self::assertSelectorTextSame('.contact-address', 'contact@example.test');
        }
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
