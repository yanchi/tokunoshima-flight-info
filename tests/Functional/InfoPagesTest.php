<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\ResetsDatabaseSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class InfoPagesTest extends WebTestCase
{
    use ResetsDatabaseSchema;

    private const OFFICIAL_SENTENCE = '最新の運航状況は、日本航空（JAL）公式サイトでご確認ください。';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // The board reads the database; start every test from empty tables.
        self::resetDatabaseSchema(static::getContainer());
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

    public function testSourcesNamesOdptAsTheTimetableSource(): void
    {
        $crawler = $this->client->request('GET', '/sources');

        self::assertSame(
            [['時刻表', '公共交通オープンデータセンター', '運営者が航空会社の公開している時刻表と照合できた期間だけ表示'], ['運航状況', 'なし', '準備中']],
            $crawler->filter('.info-table tbody tr')->each(
                static fn (Crawler $row): array => $row->filter('th, td')->each(static fn (Crawler $c): string => $c->text()),
            ),
        );
    }

    #[DataProvider('allPages')]
    public function testFooterShowsTheOdptCreditWithoutAProviderName(string $path): void
    {
        $crawler = $this->client->request('GET', $path);

        $credit = $crawler->filter('.site-footer .data-credit');
        self::assertSame(
            '本サイトが利用する公共交通データは、公共交通オープンデータセンターにおいて提供されるものです。公共交通事業者により提供されたデータを元にしていますが、必ずしも正確・完全なものとは限りません。本サイトの表示内容について、公共交通事業者への直接の問合せは行わないでください。',
            $credit->text(),
        );
        // No data provider name in the credit (§2 「クレジット表記の決定」).
        self::assertStringNotContainsString('日本航空', $credit->text());
        self::assertStringNotContainsString('JAL', $credit->text());
    }

    #[DataProvider('allPages')]
    public function testOdptCreditGivesTheContactAddressWhenSet(string $path): void
    {
        self::ensureKernelShutdown();
        $_ENV['CONTACT_ADDRESS'] = $_SERVER['CONTACT_ADDRESS'] = 'contact@example.test';
        $client = static::createClient();
        self::resetDatabaseSchema(static::getContainer());

        $client->request('GET', $path);

        // Checked on the raw HTML: the test crawler's HTML5 parser on PHP 8.4.3
        // broke a multibyte character in this text node (browsers are not affected).
        self::assertStringContainsString('本サイトに関するお問い合わせは、以下のメールアドレスにお願いします。', (string) $client->getResponse()->getContent());
        self::assertSelectorTextSame('.data-credit-contact', 'contact@example.test');
    }
}
