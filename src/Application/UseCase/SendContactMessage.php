<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Application\DTO\ContactMessage;
use App\Application\Port\ContactMessageNotSent;
use App\Application\Port\ContactMessageSenderInterface;
use Psr\Clock\ClockInterface;

final readonly class SendContactMessage
{
    public function __construct(
        private ContactMessageSenderInterface $sender,
        private ClockInterface $clock,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->sender->isConfigured();
    }

    /**
     * @throws ContactMessageNotSent
     */
    public function __invoke(string $body, ?string $replyTo): void
    {
        $replyTo = null === $replyTo ? null : trim($replyTo);

        $this->sender->send(new ContactMessage(trim($body), '' === $replyTo ? null : $replyTo, $this->clock->now()));
    }
}
