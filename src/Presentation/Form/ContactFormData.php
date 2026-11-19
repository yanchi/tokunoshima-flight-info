<?php

declare(strict_types=1);

namespace App\Presentation\Form;

use App\Application\Contact\MailAddress;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class ContactFormData
{
    #[Assert\NotBlank(message: 'お問い合わせの内容を入力してください。')]
    #[Assert\Length(max: 2000, maxMessage: 'お問い合わせの内容は2000文字以内で入力してください。')]
    public ?string $body = null;

    #[Assert\Length(max: 254, maxMessage: '返信先は254文字以内で入力してください。')]
    public ?string $replyTo = null;

    /** Honeypot: people never see this field, so a filled value means a bot. */
    public ?string $trap = null;

    #[Assert\Callback]
    public function validateReplyTo(ExecutionContextInterface $context): void
    {
        if (null !== $this->replyTo && '' !== trim($this->replyTo) && !$this->isAcceptedAddress($this->replyTo)) {
            $context->buildViolation('返信先のメールアドレスの形式が正しくありません。')->atPath('replyTo')->addViolation();
        }
    }

    /**
     * Two checks, one message: the plain ASCII address form that the browsers'
     * e-mail fields use (so a Unicode local part, which the SMTP server may
     * refuse, is turned back here), and the form the mailer accepts.
     */
    private function isAcceptedAddress(string $value): bool
    {
        return false !== filter_var(trim($value), \FILTER_VALIDATE_EMAIL) && MailAddress::isValid($value);
    }
}
