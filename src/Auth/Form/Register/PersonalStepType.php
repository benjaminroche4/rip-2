<?php

namespace App\Auth\Form\Register;

use App\Auth\Domain\Register\Personal;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class PersonalStepType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', TextType::class, [
                'label' => 'register.form.firstName.label',
                'attr' => [
                    'autocomplete' => 'given-name',
                    'placeholder' => 'register.form.firstName.placeholder',
                ],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'register.form.lastName.label',
                'attr' => [
                    'autocomplete' => 'family-name',
                    'placeholder' => 'register.form.lastName.placeholder',
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'register.form.email.label',
                'attr' => [
                    'autocomplete' => 'email',
                    'placeholder' => 'register.form.email.placeholder',
                ],
            ])
            ->add('plainPassword', PasswordType::class, [
                'label' => 'register.form.password.label',
                'attr' => [
                    'autocomplete' => 'new-password',
                    'placeholder' => 'register.form.password.placeholder',
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Personal::class,
        ]);
    }
}
