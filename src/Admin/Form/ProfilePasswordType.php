<?php

declare(strict_types=1);

namespace App\Admin\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotCompromisedPassword;
use Symfony\Component\Validator\Constraints\PasswordStrength;

/**
 * "Change my password" card on the profile page: current password check plus
 * the same strength requirements as the reset-password flow. Nothing is
 * mapped — the controller hashes and persists.
 */
class ProfilePasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('currentPassword', PasswordType::class, [
                'label' => 'admin.profile.password.current.label',
                'mapped' => false,
                'attr' => ['autocomplete' => 'current-password'],
                'constraints' => [
                    new NotBlank(message: 'admin.profile.password.current.notBlank'),
                    new UserPassword(message: 'admin.profile.password.current.invalid'),
                ],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'options' => [
                    'attr' => ['autocomplete' => 'new-password'],
                ],
                'first_options' => [
                    'label' => 'admin.profile.password.new.label',
                    'constraints' => [
                        new NotBlank(message: 'admin.profile.password.new.notBlank'),
                        new Length(min: 6, max: 4096, minMessage: 'admin.profile.password.new.minLength'),
                        // Same bar as the public reset-password flow.
                        new PasswordStrength(),
                        new NotCompromisedPassword(),
                    ],
                ],
                'second_options' => [
                    'label' => 'admin.profile.password.repeat.label',
                ],
                'invalid_message' => 'admin.profile.password.notMatch',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'messages',
        ]);
    }
}
