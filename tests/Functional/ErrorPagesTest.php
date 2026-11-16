<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\ResetsDatabaseSchema;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The error pages as production shows them: the kernel runs without debug,
 * so Symfony renders templates/bundles/TwigBundle/Exception/ instead of its
 * exception page.
 *
 * That kernel never checks its cache for freshness, so it has its own cache
 * directory (App\Kernel::getCacheDir()), cleared before this class runs.
 */
final class ErrorPagesTest extends WebTestCase
{
    use ResetsDatabaseSchema;

    private const OFFICIAL_SENTENCE = '最新の運航状況は、日本航空（JAL）公式サイトでご確認ください。';

    private KernelBrowser $client;

    public static function setUpBeforeClass(): void
    {
        (new Filesystem())->remove(\dirname(__DIR__, 2).'/var/cache/test_nodebug');
    }

    protected function setUp(): void
    {
        $this->client = static::createClient(['debug' => false]);
        self::resetDatabaseSchema(static::getContainer());
    }

    /**
     * @return iterable<string, array{string, int, string, string}>
     */
    public static function badRequests(): iterable
    {
        yield 'date with slashes' => ['/flights?date=2026/11/17', 400, '指定が正しくありません', '日付を指定した場合は、半角の数字で「年-月-日」の形'];
        yield 'date that does not exist' => ['/flights?date=2026-02-30', 400, '指定が正しくありません', '日付を指定した場合は、半角の数字で「年-月-日」の形'];
        yield 'unknown direction on /flights' => ['/flights?direction=north', 400, '指定が正しくありません', 'ページの指定が正しくないため、表示できません。'];
        yield 'unknown direction on /' => ['/?direction=north', 400, '指定が正しくありません', 'ページの指定が正しくないため、表示できません。'];
        yield 'unknown page' => ['/no-such-page', 404, 'ページが見つかりません', 'URL をご確認ください'];
        yield 'method not allowed' => ['/about', 405, 'ページを表示できません', 'このページは表示できません。'];
    }

    #[DataProvider('badRequests')]
    public function testShowsAJapaneseErrorPage(string $path, int $status, string $heading, string $text): void
    {
        $method = 405 === $status ? 'POST' : 'GET';
        $crawler = $this->client->request($method, $path);

        self::assertResponseStatusCodeSame($status);
        self::assertSelectorTextSame('h1', $heading);
        self::assertPageTitleSame($heading.' | 徳之島空港 発着案内（非公式）');
        self::assertStringContainsString($text, $crawler->filter('.error-message')->text());
        self::assertSelectorTextSame('.error-code', 'エラーコード：'.$status);
        $this->assertCommonParts($crawler);
    }

    public function testDoesNotShowTheExceptionMessage(): void
    {
        $this->client->request('GET', '/flights?date=2026/11/17');

        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('Invalid date', $content);
        self::assertStringNotContainsString('BadRequestHttpException', $content);
    }

    public function testServerErrorPageHidesTheException(): void
    {
        $renderer = static::getContainer()->get('error_renderer.html');

        $error = $renderer->render(new \RuntimeException('secret detail /var/app/src/Foo.php'));

        self::assertSame(500, $error->getStatusCode());
        $crawler = new Crawler($error->getAsString());
        self::assertSame('ページを表示できません', $crawler->filter('h1')->text());
        self::assertStringContainsString('時間をおいて、もう一度お試しください。', $crawler->filter('.error-message')->text());
        self::assertSame('エラーコード：500', $crawler->filter('.error-code')->text());
        $this->assertCommonParts($crawler);
        self::assertStringNotContainsString('secret detail', $error->getAsString());
        self::assertStringNotContainsString('RuntimeException', $error->getAsString());
        self::assertStringNotContainsString('Foo.php', $error->getAsString());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function clientErrors(): iterable
    {
        yield '400' => ['GET', '/flights?date=2026/11/17'];
        yield '404' => ['GET', '/no-such-page'];
        yield '405' => ['POST', '/about'];
    }

    /**
     * A visitor's bad URL is logged below the prod fingers_crossed action
     * level (error), so it neither alerts nor flushes the buffer.
     */
    #[DataProvider('clientErrors')]
    public function testClientErrorsAreLoggedAtInfo(string $method, string $path): void
    {
        $logger = static::getContainer()->get('monolog.logger.request');
        $handler = new TestHandler();
        $logger->pushHandler($handler);

        $this->client->request($method, $path);

        $uncaught = array_values(array_filter(
            $handler->getRecords(),
            static fn ($record): bool => str_starts_with($record->message, 'Uncaught PHP Exception'),
        ));
        self::assertCount(1, $uncaught);
        self::assertSame(Level::Info, $uncaught[0]->level);
    }

    private function assertCommonParts(Crawler $crawler): void
    {
        self::assertStringEndsWith('本日の発着便に戻る', $crawler->filter('.back-link a')->text());
        self::assertSame('/', $crawler->filter('.back-link a')->attr('href'));
        self::assertStringContainsString(self::OFFICIAL_SENTENCE, $crawler->filter('.error-message')->text());
        self::assertStringContainsString(self::OFFICIAL_SENTENCE, $crawler->filter('.official-notice')->text());
        self::assertStringContainsString('非公式サイトです', $crawler->filter('.site-footer')->text());
        self::assertCount(0, $crawler->filter('a[href*="jal.co.jp"]'));
    }
}
