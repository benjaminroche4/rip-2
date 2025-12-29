<?php

namespace App\Form;

use App\Entity\PropertyEstimation;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

class PropertyEstimationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('address',TextType::class, [
                'label' => 'propertyEstimation.form.address.label',
                'constraints' => [
                    new NotBlank(
                        message: 'propertyEstimation.form.address.notBlank',
                    )
                ],
                'attr' => [
                    'data-controller' => 'google-places',
                    'data-google-places-target' => 'input',
                    'autocomplete' => 'off',
                ],
            ])
            ->add('propertyCondition', ChoiceType::class, [
                'label' => 'propertyEstimation.form.propertyCondition.label',
                'choices' => [
                    'propertyEstimation.form.propertyCondition.choice.1' => 'propertyEstimation.form.propertyCondition.choice.1',
                    'propertyEstimation.form.propertyCondition.choice.2' => 'propertyEstimation.form.propertyCondition.choice.2',
                    'propertyEstimation.form.propertyCondition.choice.3' => 'propertyEstimation.form.propertyCondition.choice.3',
                    'propertyEstimation.form.propertyCondition.choice.4' => 'propertyEstimation.form.propertyCondition.choice.4',
                ],
                'placeholder' => 'propertyEstimation.form.propertyCondition.placeholder',
                'constraints' => [
                    new NotBlank(
                        message: 'propertyEstimation.form.propertyCondition.notBlank',
                    )
                ],
            ])
            ->add('bedroom',IntegerType::class, [
                'label' => 'propertyEstimation.form.bedroom.label',
                'data' => 1,
                'constraints' => [
                    new NotBlank(
                        message: 'propertyEstimation.form.bedroom.notBlank',
                    )
                ],
                'attr' => [
                    'min' => 0,
                    'max' => 10,
                    'step' => 1,
                ],
            ])
            ->add('bathroom',IntegerType::class, [
                'label' => 'propertyEstimation.form.bathroom.label',
                'data' => 1,
                'constraints' => [
                    new NotBlank(
                        message: 'propertyEstimation.form.bathroom.notBlank',
                    )
                ],
                'attr' => [
                    'min' => 0,
                    'max' => 10,
                    'step' => 1,
                ],
            ])
            ->add('surface',IntegerType::class, [
                'label' => 'propertyEstimation.form.surface.label',
                'data' => 30,
                'constraints' => [
                    new NotBlank(
                        message: 'propertyEstimation.form.surface.notBlank',
                    )
                ],
                'attr' => [
                    'min' => 8,
                    'max' => 900,
                    'step' => 1,
                ],
            ])
            ->add('phoneNumber', TextType::class, [
                'label' => 'propertyEstimation.form.phoneNumber.label',
                'required' => false,
                'constraints' => [
                    new Regex([
                        'pattern' => '/^[0-9+()]+$/',
                        'message' => 'propertyEstimation.form.email.pattern',
                    ]),
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'propertyEstimation.form.email.label',
                'constraints' => [
                    new NotBlank(
                        message: 'propertyEstimation.form.email.notBlank',
                    )
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PropertyEstimation::class,
        ]);
    }
}
