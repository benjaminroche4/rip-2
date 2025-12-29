<?php

namespace App\Form;

use App\Entity\Contact;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

class ContactType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName',TextType::class, [
                'label' => 'contact.contactForm.firstName.label',
                'constraints' => [
                    new NotBlank(
                        message: 'contact.contactForm.firstName.notBlank',
                    )
                ],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'contact.contactForm.lastName.label',
                'constraints' => [
                    new NotBlank(
                        message: 'contact.contactForm.lastName.notBlank',
                    )
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'contact.contactForm.email.label',
                'constraints' => [
                    new NotBlank(
                        message: 'contact.contactForm.email.notBlank',
                    )
                ],
            ])
            ->add('phoneNumber', TextType::class, [
                'label' => 'contact.contactForm.phoneNumber.label',
                'required' => false,
                'constraints' => [
                    new Regex([
                        'pattern' => '/^[0-9+()]+$/',
                        'message' => 'contact.contactForm.email.pattern',
                    ]),
                ],
            ])
            ->add('company', TextType::class, [
                'label' => 'contact.contactForm.company.label',
                'required' => false,
            ])
            ->add('helpType', ChoiceType::class, [
                'label' => 'contact.contactForm.helpType.label',
                'choices' => [
                    'contact.contactForm.helpType.choice.1' => 'contact.contactForm.helpType.choice.1',
                    'contact.contactForm.helpType.choice.2' => 'contact.contactForm.helpType.choice.2',
                    'contact.contactForm.helpType.choice.3' => 'contact.contactForm.helpType.choice.3',
                    'contact.contactForm.helpType.choice.4' => 'contact.contactForm.helpType.choice.4',
                ],
                'placeholder' => 'contact.contactForm.helpType.placeholder',
                'constraints' => [
                    new NotBlank(
                        message: 'contact.contactForm.helpType.notBlank',
                    )
                ],
            ])
            ->add('message', TextareaType::class, [
                'label' => 'contact.contactForm.message.label',
                'required' => false,
                'attr' => [
                    'rows' => 5,
                ],
            ])
            ->add('accept', CheckboxType::class, [
                'mapped' => false,
                'constraints' => [
                    new IsTrue([
                        'message' => 'contact.contactForm.accept.label',
                    ]),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Contact::class,
        ]);
    }
}
