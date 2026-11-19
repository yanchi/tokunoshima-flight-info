<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\ResetsDatabaseSchema;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

/**
 * Test-only addresses (example.test) and message texts; nothing is sent: the
 * test environment uses the null mailer transport.
 */
final class ContactFormTest extends WebTestCase
{
    use MailerAssertionsTrait;
    use ResetsDatabaseSchema;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::setEnv('CONTACT_RECIPIENT', 'operator@example.test');
        self::setEnv('CONTACT_FROM', 'sender@example.test');
        $this->client = static::createClient();
        self::resetDatabaseSchema(static::getContainer());
        // The rate limiter state lives in the cache pool; start every test empty.
        static::getContainer()->get('cache.rate_limiter')->clear();
    }

    protected function tearDown(): void
    {
        self::setEnv('CONTACT_RECIPIENT', null);
        self::setEnv('CONTACT_FROM', null);
        parent::tearDown();
    }

    public function testShowsTheFormWithTheNotes(): void
    {
        $this->client->request('GET', '/contact');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'お問い合わせ');
        self::assertSelectorTextContains('main', 'このフォームの宛先は、本サイトの運営者です。');
        self::assertSelectorTextContains('main', '航空会社への問い合わせはご遠慮ください');
        self::assertSelectorExists('form.contact-form textarea');
        self::assertSelectorExists('form.contact-form input[type="email"]');
        self::assertSelectorNotExists('main a[href^="mailto:"]');
    }

    public function testNeitherAddressIsShownOnThePage(): void
    {
        $this->client->request('GET', '/contact');

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('operator@example.test', $html);
        self::assertStringNotContainsString('sender@example.test', $html);
    }

    public function testSendsTheMessageToTheOperatorWithReplyTo(): void
    {
        $this->submit('表示の誤りを見つけました。', 'visitor@example.test');

        self::assertResponseRedirects('/contact/sent');
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailAddressContains($email, 'To', 'operator@example.test');
        self::assertEmailAddressContains($email, 'From', 'sender@example.test');
        self::assertEmailAddressContains($email, 'Reply-To', 'visitor@example.test');
        self::assertEmailHeaderSame($email, 'Subject', '【徳之島空港 運航情報】お問い合わせ');
        self::assertStringContainsString('表示の誤りを見つけました。', (string) $email->getTextBody());
    }

    public function testSendsWithoutReplyTo(): void
    {
        $this->submit('返信は不要です。', '');

        self::assertResponseRedirects('/contact/sent');
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailHeaderNotSame($email, 'Reply-To', 'x');
        self::assertCount(0, $email->getReplyTo());
        self::assertStringContainsString('（入力なし）', (string) $email->getTextBody());
    }

    public function testSentPageConfirmsReceipt(): void
    {
        $this->client->request('GET', '/contact/sent');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role="status"]', 'お問い合わせを受け付けました');
    }

    public function testEmptyBodyIsRejectedAndNothingIsSent(): void
    {
        $this->submit('   ', '');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'お問い合わせの内容を入力してください。');
        self::assertEmailCount(0);
    }

    public function testTooLongBodyIsRejected(): void
    {
        $this->submit(str_repeat('あ', 2001), '');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', '2000文字以内');
        self::assertEmailCount(0);
    }

    public function testBadReplyToIsRejectedAndKeepsTheBody(): void
    {
        $this->submit('内容です。', 'not-an-address');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', '返信先のメールアドレスの形式が正しくありません。');
        self::assertStringContainsString('内容です。', (string) $this->client->getResponse()->getContent());
        self::assertEmailCount(0);
    }

    public function testReplyToWithAHeaderInjectionIsRejected(): void
    {
        $this->submit('内容です。', "a@example.test\r\nBcc: victim@example.test");

        self::assertResponseStatusCodeSame(422);
        self::assertEmailCount(0);
    }

    public function testFilledHoneypotSendsNothingButLooksSuccessful(): void
    {
        $this->submit('宣伝です。', '', 'http://spam.example.test/');

        self::assertResponseRedirects('/contact/sent');
        self::assertEmailCount(0);
    }

    public function testMissingCsrfTokenIsRejected(): void
    {
        $this->client->request('POST', '/contact', ['contact_form' => ['body' => '内容です。', 'replyTo' => '']]);

        self::assertResponseStatusCodeSame(422);
        self::assertEmailCount(0);
    }

    public function testSixthSubmissionFromOneClientIsLimited(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->submit('内容です。', '');
            self::assertResponseRedirects('/contact/sent');
        }

        $this->submit('内容です。', '');

        self::assertResponseStatusCodeSame(429);
        self::assertSelectorTextContains('[role="alert"]', '送信の回数が多いため');
        // The kernel is rebooted per request, so this counts the last request only.
        self::assertEmailCount(0);
    }

    public function testGlobalLimitStopsEveryClient(): void
    {
        $limiter = static::getContainer()->get('limiter.contact_form_global');
        $limiter->create('all')->consume(50);

        $this->submit('内容です。', '');

        self::assertResponseStatusCodeSame(429);
        self::assertEmailCount(0);
    }

    public function testMailFailureShowsAnErrorAndKeepsTheInput(): void
    {
        self::ensureKernelShutdown();
        // Nothing listens on this port: the connection is refused at once.
        self::setEnv('MAILER_DSN', 'smtp://127.0.0.1:1');
        $this->client = static::createClient();

        try {
            $this->submit('失敗しても残る内容です。', '');
        } finally {
            self::setEnv('MAILER_DSN', null);
        }

        self::assertResponseStatusCodeSame(503);
        self::assertSelectorTextContains('[role="alert"]', '送信できませんでした。');
        self::assertStringContainsString('失敗しても残る内容です。', (string) $this->client->getResponse()->getContent());
    }

    public function testShowsPendingWhileTheAddressesAreNotConfigured(): void
    {
        self::ensureKernelShutdown();
        self::setEnv('CONTACT_RECIPIENT', '');
        $client = static::createClient();

        $client->request('GET', '/contact');

        self::assertResponseStatusCodeSame(503);
        self::assertSelectorTextContains('[role="alert"]', 'お問い合わせフォームは準備中です。');
        self::assertSelectorNotExists('form');
    }

    private function submit(string $body, string $replyTo, string $honeypot = ''): void
    {
        $crawler = $this->client->request('GET', '/contact');
        $form = $crawler->selectButton('送信する')->form([
            'contact_form[body]' => $body,
            'contact_form[replyTo]' => $replyTo,
            'contact_form[website]' => $honeypot,
        ]);
        $this->client->submit($form);
    }

    private static function setEnv(string $name, ?string $value): void
    {
        if (null === $value) {
            unset($_ENV[$name], $_SERVER[$name]);

            return;
        }
        $_ENV[$name] = $_SERVER[$name] = $value;
    }
}
