<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Application\DTO\ContactMessage;

interface ContactMessageSenderInterface
{
    /**
     * False while the recipient or the sender address is not configured.
     */
    public function isConfigured(): bool;

    /**
     * @throws ContactMessageNotSent when the message could not be handed over
     */
    public function send(ContactMessage $message): void;
}
