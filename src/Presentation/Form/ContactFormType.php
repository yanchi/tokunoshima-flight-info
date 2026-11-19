<?php

declare(strict_types=1);

namespace App\Presentation\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<ContactFormData>
 */
final class ContactFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('body', TextareaType::class, ['label' => 'お問い合わせの内容（必須）', 'required' => false, 'attr' => ['rows' => 8]])
            ->add('replyTo', EmailType::class, ['label' => '返信先のメールアドレス（任意）', 'required' => false, 'attr' => ['autocomplete' => 'email']])
            ->add('trap', TextType::class, ['label' => false, 'required' => false, 'attr' => ['tabindex' => '-1', 'autocomplete' => 'off']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ContactFormData::class,
            'csrf_token_id' => 'contact',
        ]);
    }
}
