<?php

declare(strict_types=1);

namespace App\Dossier\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Blank;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Pairing step of the public deposit page: the visitor identifies with the
 * email of a dossier person plus the dossier pairing code (sent by email by
 * the file module). The match itself is checked in the controller and always
 * fails with a generic message, never revealing which field is wrong.
 */
final class DepositPairingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'deposit.pairing.email.label',
                'constraints' => [
                    new NotBlank(message: 'deposit.pairing.email.required'),
                    new Email(message: 'deposit.pairing.email.invalid'),
                ],
                'attr' => [
                    'autocomplete' => 'email',
                    'placeholder' => 'deposit.pairing.email.placeholder',
                ],
            ])
            ->add('code', TextType::class, [
                'label' => 'deposit.pairing.code.label',
                'constraints' => [
                    new NotBlank(message: 'deposit.pairing.code.required'),
                    new Length(exactly: 6, exactMessage: 'deposit.pairing.code.invalid'),
                ],
                'attr' => [
                    'autocomplete' => 'off',
                    'autocapitalize' => 'characters',
                    'spellcheck' => 'false',
                    'maxlength' => 6,
                ],
            ])
            // Honeypot: invisible to humans, frequently auto-filled by bots.
            ->add('website', TextType::class, [
                'required' => false,
                'label' => false,
                'attr' => [
                    'autocomplete' => 'off',
                    'tabindex' => -1,
                    'aria-hidden' => 'true',
                    'class' => 'sr-only',
                ],
                'constraints' => [
                    new Blank(),
                ],
            ])
        ;
    }
}
