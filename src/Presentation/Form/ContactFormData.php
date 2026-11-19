<?php

declare(strict_types=1);

namespace App\Presentation\Form;

use App\Infrastructure\Contact\MailAddress;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class ContactFormData
{
    #[Assert\NotBlank(message: 'お問い合わせの内容を入力してください。')]
    #[Assert\Length(max: 2000, maxMessage: 'お問い合わせの内容は2000文字以内で入力してください。')]
    public ?string $body = null;

    #[Assert\Length(max: 254, maxMessage: '返信先は254文字以内で入力してください。')]
    #[Assert\Email(message: '返信先のメールアドレスの形式が正しくありません。', mode: Assert\Email::VALIDATION_MODE_HTML5)]
    public ?string $replyTo = null;

    /** Honeypot: people never see this field, so a filled value means a bot. */
    public ?string $trap = null;

    #[Assert\Callback]
    public function validateReplyToAcceptedByTheMailer(ExecutionContextInterface $context): void
    {
        if (null !== $this->replyTo && '' !== trim($this->replyTo) && !MailAddress::isValid($this->replyTo)) {
            $context->buildViolation('返信先のメールアドレスの形式が正しくありません。')->atPath('replyTo')->addViolation();
        }
    }
}
