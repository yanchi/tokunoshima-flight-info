<?php

declare(strict_types=1);

namespace App\Application\DTO;

/**
 * A message from a visitor to the site operator. Never stored: it is only
 * handed to the sender (docs/data-source-verification.md §8 「問い合わせフォーム」).
 */
final readonly class ContactMessage
{
    public function __construct(
        public string $body,
        /** Optional address to reply to; null when the visitor gave none. */
        public ?string $replyTo,
        public \DateTimeImmutable $receivedAt,
    ) {
    }
}
