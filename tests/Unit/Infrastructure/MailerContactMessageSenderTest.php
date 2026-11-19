<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure;

use App\Application\DTO\ContactMessage;
use App\Application\Port\ContactMessageNotSent;
use App\Infrastructure\Contact\MailerContactMessageSender;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Test-only addresses and texts.
 */
final class MailerContactMessageSenderTest extends TestCase
{
    private const DELIVERING_DSN = 'smtp://u:p@mail.invalid:587';

    public function testIsConfiguredOnlyWithBothAddresses(): void
    {
        self::assertTrue($this->sender()->isConfigured());
        self::assertFalse($this->sender(recipient: '')->isConfigured());
        self::assertFalse($this->sender(from: ' ')->isConfigured());
    }

    public function testInProductionANonDeliveringDsnMakesItUnavailable(): void
    {
        self::assertFalse($this->sender(dsn: 'null://null', environment: 'prod')->isConfigured());
        self::assertFalse($this->sender(dsn: 'native://default', environment: 'prod')->isConfigured());
        self::assertTrue($this->sender(dsn: self::DELIVERING_DSN, environment: 'prod')->isConfigured());
        // Outside production the null transport is the normal setting.
        self::assertTrue($this->sender(dsn: 'null://null', environment: 'test')->isConfigured());
    }

    public function testRefusesToSendWhenNotConfigured(): void
    {
        $this->expectException(ContactMessageNotSent::class);
        $this->sender(recipient: '', from: '')->send($this->message());
    }

    public function testWritesTheReceivedTimeInJstAcrossMidnight(): void
    {
        $mailer = new RecordingMailer();

        $this->sender(mailer: $mailer)->send(new ContactMessage('内容です。', null, new \DateTimeImmutable('2026-11-19T15:30:00Z')));

        self::assertInstanceOf(Email::class, $mailer->sent);
        self::assertStringContainsString('受付日時（日本時間）：2026-11-20 00:30', (string) $mailer->sent->getTextBody());
    }

    public function testTransportFailureBecomesNotSentAndTheLogHasNoPersonalData(): void
    {
        $logger = new MemoryLogger();
        $mailer = new RecordingMailer(new TransportException('connect to smtp://user:secret-password@mail.invalid failed'));

        $this->assertNotSentAndLogIsClean($this->sender(mailer: $mailer, logger: $logger), $this->message(), $logger);
    }

    public function testAnAddressTheMailerRefusesBecomesNotSentAndIsNotLogged(): void
    {
        $logger = new MemoryLogger();
        $message = new ContactMessage('private-body-text', 'a..b@example.test', new \DateTimeImmutable('2026-11-19T00:00:00Z'));

        $this->assertNotSentAndLogIsClean($this->sender(logger: $logger), $message, $logger);
    }

    private function assertNotSentAndLogIsClean(MailerContactMessageSender $sender, ContactMessage $message, MemoryLogger $logger): void
    {
        try {
            $sender->send($message);
            self::fail('Expected ContactMessageNotSent.');
        } catch (ContactMessageNotSent) {
        }

        $logged = json_encode($logger->records, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        self::assertCount(1, $logger->records);
        self::assertStringNotContainsString('secret-password', $logged);
        self::assertStringNotContainsString('visitor@example.test', $logged);
        self::assertStringNotContainsString('a..b@example.test', $logged);
        self::assertStringNotContainsString('private-body-text', $logged);
    }

    private function message(): ContactMessage
    {
        return new ContactMessage('private-body-text', 'visitor@example.test', new \DateTimeImmutable('2026-11-19T00:00:00Z'));
    }

    private function sender(
        string $recipient = 'to@example.test',
        string $from = 'from@example.test',
        string $dsn = self::DELIVERING_DSN,
        string $environment = 'test',
        ?RecordingMailer $mailer = null,
        ?MemoryLogger $logger = null,
    ): MailerContactMessageSender {
        return new MailerContactMessageSender($mailer ?? new RecordingMailer(), $logger ?? new MemoryLogger(), $recipient, $from, $dsn, $environment);
    }
}

final class RecordingMailer implements MailerInterface
{
    public ?RawMessage $sent = null;

    public function __construct(private readonly ?\Throwable $failure = null)
    {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }
        $this->sent = $message;
    }
}

final class MemoryLogger extends AbstractLogger
{
    /** @var list<array{string, string, array<mixed>}> */
    public array $records = [];

    /**
     * @param array<mixed> $context
     */
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [\is_string($level) ? $level : 'unknown', (string) $message, $context];
    }
}
