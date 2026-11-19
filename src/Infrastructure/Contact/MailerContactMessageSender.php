<?php

declare(strict_types=1);

namespace App\Infrastructure\Contact;

use App\Application\Contact\MailAddress;
use App\Application\DTO\ContactMessage;
use App\Application\Port\ContactMessageNotSent;
use App\Application\Port\ContactMessageSenderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Mails the message to the operator. The body and the reply-to address are
 * never logged (CLAUDE.md §8: no personal information in logs).
 */
final readonly class MailerContactMessageSender implements ContactMessageSenderInterface
{
    private const SUBJECT = '【徳之島空港 運航情報】お問い合わせ';

    public function __construct(
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        #[Autowire(env: 'CONTACT_RECIPIENT')]
        private string $recipient,
        #[Autowire(env: 'CONTACT_FROM')]
        private string $from,
        #[Autowire(env: 'MAILER_DSN')]
        private string $mailerDsn,
        #[Autowire('%kernel.environment%')]
        private string $environment,
    ) {
    }

    public function isConfigured(): bool
    {
        // In production a DSN that delivers nothing would answer "received" and
        // lose the message, so the form is treated as not available.
        if ('prod' === $this->environment && !MailerDsnCheck::delivers($this->mailerDsn)) {
            return false;
        }

        // An address the mailer refuses would fail on every submission.
        return '' !== trim($this->recipient) && '' !== trim($this->from)
            && MailAddress::isValid($this->recipient) && MailAddress::isValid($this->from);
    }

    public function send(ContactMessage $message): void
    {
        if (!$this->isConfigured()) {
            throw new ContactMessageNotSent('The contact recipient or sender is not configured.');
        }

        try {
            $email = (new Email())
                ->from(new Address(trim($this->from), '徳之島空港 運航情報'))
                ->to(new Address(trim($this->recipient)))
                ->subject(self::SUBJECT)
                ->text(\sprintf(
                    "受付日時（日本時間）：%s\n返信先：%s\n\n%s\n",
                    $message->receivedAt->setTimezone(new \DateTimeZone('Asia/Tokyo'))->format('Y-m-d H:i'),
                    $message->replyTo ?? '（入力なし）',
                    $message->body,
                ));
            if (null !== $message->replyTo) {
                $email->replyTo(new Address($message->replyTo));
            }
        } catch (\InvalidArgumentException $e) {
            // The exception text carries the offending address: log the class only.
            $this->logger->error('Contact message was not built.', ['exception_class' => $e::class]);

            throw new ContactMessageNotSent('The contact message could not be built.', 0, $e);
        }

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            // Class only: the exception text can carry the address or the DSN.
            $this->logger->error('Contact message was not sent.', ['exception_class' => $e::class]);

            throw new ContactMessageNotSent('The contact message was not sent.', 0, $e);
        }
    }
}
