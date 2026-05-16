<?php

namespace App\Auth\Form\Register;

use App\Auth\Domain\Register\Account;
use App\Auth\Domain\Situation;
use App\Shared\Form\DataTransformer\PhoneNumberE164Transformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CountryType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class AccountStepType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('phoneNumber', TelType::class, [
                'label' => 'register.form.phoneNumber.label',
                'invalid_message' => 'register.phoneNumber.invalidFormat',
                'attr' => [
                    'autocomplete' => 'tel',
                ],
            ])
            ->add('nationality', CountryType::class, [
                'label' => 'register.form.nationality.label',
                'placeholder' => 'register.form.nationality.placeholder',
                'preferred_choices' => ['FR', 'GB', 'US', 'CH', 'BE', 'CA', 'DE', 'IT', 'ES'],
                'duplicate_preferred_choices' => false,
                'attr' => [
                    'autocomplete' => 'country',
                ],
            ])
            ->add('situation', EnumType::class, [
                'class' => Situation::class,
                'label' => 'register.form.situation.label',
                'expanded' => true,
                'multiple' => false,
                'choice_label' => fn (Situation $s) => $s->value,
            ])
        ;

        $builder->get('phoneNumber')->addModelTransformer(new PhoneNumberE164Transformer());
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Account::class,
        ]);
    }
}
