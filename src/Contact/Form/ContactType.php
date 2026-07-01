<?php

namespace App\Contact\Form;

use App\Contact\Entity\Contact;
use App\Shared\Form\DataTransformer\PhoneNumberE164Transformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Blank;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;

class ContactType extends AbstractType
{
    /**
     * helpType value that unlocks the offer selection (housing search).
     */
    public const string HOUSING_HELP_TYPE = 'contact.contactForm.helpType.choice.1';

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', TextType::class, [
                'label' => 'contact.contactForm.firstName.label',
                'constraints' => [
                    new NotBlank(
                        message: 'contact.contactForm.firstName.notBlank',
                    ),
                ],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'contact.contactForm.lastName.label',
                'constraints' => [
                    new NotBlank(
                        message: 'contact.contactForm.lastName.notBlank',
                    ),
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'contact.contactForm.email.label',
                'constraints' => [
                    new NotBlank(
                        message: 'contact.contactForm.email.notBlank',
                    ),
                ],
            ])
            ->add('phoneNumber', TelType::class, [
                'label' => 'contact.contactForm.phoneNumber.label',
                'invalid_message' => 'contact.contactForm.phoneNumber.invalidFormat',
                'constraints' => [
                    new NotBlank(
                        message: 'contact.contactForm.phoneNumber.notBlank',
                    ),
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
                    ),
                ],
            ])
            // Conditional offer selection, only relevant (and only shown) when
            // the help type is "housing search". Unmapped: it does not persist
            // on the Contact entity, it only enriches the emails / webhook.
            ->add('offer', ChoiceType::class, [
                'label' => false,
                'mapped' => false,
                'required' => false,
                'expanded' => true,
                'multiple' => false,
                // Suppress the empty "placeholder" radio Symfony would otherwise
                // add for a non-required expanded choice (the phantom 3rd option).
                'placeholder' => false,
                'choices' => [
                    'contact.contactForm.offer.accompagne.title' => 'accompagne',
                    'contact.contactForm.offer.confie.title' => 'confie',
                ],
                'choice_attr' => static fn (): array => ['data-conditional-offer-target' => 'input'],
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
                    new IsTrue(message: 'contact.contactForm.accept.label'),
                ],
            ])
            // Honeypot: invisible to humans, frequently auto-filled by bots.
            // If non-empty, validation fails silently as if it were a normal
            // form error — the bot gets a 422 with no clue why.
            ->add('website', TextType::class, [
                'mapped' => false,
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

        $builder->get('phoneNumber')->addModelTransformer(new PhoneNumberE164Transformer());

        // Require an offer only when the user selected the housing-search help
        // type; otherwise the field stays optional (and hidden client-side).
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $form = $event->getForm();

            if (!$form->has('helpType') || !$form->has('offer')) {
                return;
            }

            $offer = $form->get('offer')->getData();

            if (self::HOUSING_HELP_TYPE === $form->get('helpType')->getData()
                && (null === $offer || '' === $offer)) {
                $form->get('offer')->addError(new FormError(
                    $this->translator->trans('contact.contactForm.offer.notBlank'),
                ));
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Contact::class,
        ]);
    }
}
