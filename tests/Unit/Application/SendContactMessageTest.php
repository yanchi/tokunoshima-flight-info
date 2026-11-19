<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application;

use App\Application\DTO\ContactMessage;
use App\Application\Port\ContactMessageSenderInterface;
use App\Application\UseCase\SendContactMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Test-only texts and addresses.
 */
final class SendContactMessageTest extends TestCase
{
    public function testTrimsTheInputAndStampsTheReceivedTime(): void
    {
        $sender = new RecordingSender();
        $useCase = new SendContactMessage($sender, new MockClock('2026-11-19 03:00:00 UTC'));

        $useCase("  内容です。\n", ' visitor@example.test ');

        self::assertCount(1, $sender->sent);
        self::assertSame('内容です。', $sender->sent[0]->body);
        self::assertSame('visitor@example.test', $sender->sent[0]->replyTo);
        self::assertSame('2026-11-19T03:00:00+00:00', $sender->sent[0]->receivedAt->format(\DATE_ATOM));
    }

    public function testBlankReplyToBecomesNull(): void
    {
        $sender = new RecordingSender();
        $useCase = new SendContactMessage($sender, new MockClock());

        $useCase('内容です。', '   ');

        self::assertNull($sender->sent[0]->replyTo);
    }

    public function testAvailabilityFollowsTheSender(): void
    {
        self::assertFalse((new SendContactMessage(new RecordingSender(false), new MockClock()))->isAvailable());
        self::assertTrue((new SendContactMessage(new RecordingSender(true), new MockClock()))->isAvailable());
    }
}

final class RecordingSender implements ContactMessageSenderInterface
{
    /** @var list<ContactMessage> */
    public array $sent = [];

    public function __construct(private readonly bool $configured = true)
    {
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function send(ContactMessage $message): void
    {
        $this->sent[] = $message;
    }
}
