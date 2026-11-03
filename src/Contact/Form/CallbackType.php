<?php

declare(strict_types=1);

namespace App\Contact\Form;

use App\Contact\Domain\ContactMethod;
use App\Contact\Domain\ContactSlot;
use App\Contact\Domain\Furnishing;
use App\Contact\Domain\Guarantor;
use App\Contact\Domain\RentBudget;
use App\Contact\Domain\Rooms;
use App\Contact\Domain\SettleTiming;
use App\Contact\Domain\Situation;
use App\Contact\Entity\Contact;
use App\Shared\Form\DataTransformer\PhoneNumberE164Transformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Blank;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * "On vous rappelle" call-back form. Same storage and validation messages as
 * the contact form (shared fields), plus the call-back answers stored as enums.
 * Card choices are expanded radios (styled as cards in the template, no JS).
 *
 * @extends AbstractType<Contact>
 */
final class CallbackType extends AbstractType
{
    /** @var list<string> Contact::OFFERS, most complete package first */
    private const OFFER_ORDER = ['confie', 'accompagne'];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', TextType::class, [
                'label' => 'callback.form.firstName.label',
                'constraints' => [new NotBlank(message: 'contact.contactForm.firstName.notBlank')],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'callback.form.lastName.label',
                'constraints' => [new NotBlank(message: 'contact.contactForm.lastName.notBlank')],
            ])
            ->add('situation', EnumType::class, [
                'class' => Situation::class,
                'label' => 'callback.form.situation.label',
                'expanded' => true,
                'placeholder' => false,
                'choice_label' => static fn (Situation $choice): string => $choice->labelKey(),
                'constraints' => [new NotBlank(message: 'callback.form.situation.notBlank')],
            ])
            // Easy, commitment-free questions first; the price question comes last
            ->add('settleTiming', EnumType::class, [
                'class' => SettleTiming::class,
                'label' => 'callback.form.settleTiming.label',
                'expanded' => true,
                'placeholder' => false,
                'choice_label' => static fn (SettleTiming $choice): string => $choice->labelKey(),
                'constraints' => [new NotBlank(message: 'callback.form.settleTiming.notBlank')],
            ])
            // Same packages as the contact form and the pricing page (Contact::OFFERS)
            ->add('offer', ChoiceType::class, [
                'label' => 'callback.form.offer.label',
                'expanded' => true,
                'placeholder' => false,
                // Premium package first: price anchoring, same order as the pricing page on mobile
                'choices' => array_combine(
                    array_map(static fn (string $offer): string => 'contact.contactForm.offer.'.$offer.'.title', self::OFFER_ORDER),
                    self::OFFER_ORDER,
                ),
                'constraints' => [
                    new NotBlank(message: 'callback.form.offer.notBlank'),
                    new Choice(choices: Contact::OFFERS),
                ],
            ])
            // Step 2: the search itself
            ->add('furnishing', EnumType::class, [
                'class' => Furnishing::class,
                'label' => 'callback.form.furnishing.label',
                'expanded' => true,
                'placeholder' => false,
                'choice_label' => static fn (Furnishing $choice): string => $choice->labelKey(),
                'constraints' => [new NotBlank(message: 'callback.form.furnishing.notBlank')],
            ])
            ->add('rooms', EnumType::class, [
                'class' => Rooms::class,
                'label' => 'callback.form.rooms.label',
                'expanded' => true,
                'placeholder' => false,
                'choice_label' => static fn (Rooms $choice): string => $choice->labelKey(),
                'constraints' => [new NotBlank(message: 'callback.form.rooms.notBlank')],
            ])
            // Maximum rent, one choice
            ->add('rentBudget', EnumType::class, [
                'class' => RentBudget::class,
                'label' => 'callback.form.rentBudget.label',
                'expanded' => true,
                'placeholder' => false,
                'choice_label' => static fn (RentBudget $choice): string => $choice->labelKey(),
                'constraints' => [new NotBlank(message: 'callback.form.rentBudget.notBlank')],
            ])
            ->add('guarantor', EnumType::class, [
                'class' => Guarantor::class,
                'label' => 'callback.form.guarantor.label',
                'expanded' => true,
                'placeholder' => false,
                'choice_label' => static fn (Guarantor $choice): string => $choice->labelKey(),
                // Keeps the visitor on the step: a reassurance shows up under these answers
                'choice_attr' => static fn (Guarantor $choice): array => Guarantor::France === $choice ? [] : ['data-form-steps-hold' => ''],
                'constraints' => [new NotBlank(message: 'callback.form.guarantor.notBlank')],
            ])
            // Step 3: how to reach the visitor
            ->add('contactMethod', EnumType::class, [
                'class' => ContactMethod::class,
                'label' => 'callback.form.contactMethod.label',
                'expanded' => true,
                'required' => false,
                'placeholder' => false,
                'choice_label' => static fn (ContactMethod $choice): string => $choice->labelKey(),
            ])
            ->add('phoneNumber', TelType::class, [
                'label' => 'callback.form.phoneNumber.label',
                'invalid_message' => 'contact.contactForm.phoneNumber.invalidFormat',
                'constraints' => [new NotBlank(message: 'contact.contactForm.phoneNumber.notBlank')],
            ])
            ->add('email', EmailType::class, [
                'label' => 'callback.form.email.label',
                'constraints' => [new NotBlank(message: 'contact.contactForm.email.notBlank')],
            ])
            ->add('contactSlot', EnumType::class, [
                'class' => ContactSlot::class,
                'label' => 'callback.form.contactSlot.label',
                'expanded' => true,
                'required' => false,
                'placeholder' => false,
                'choice_label' => static fn (ContactSlot $choice): string => $choice->labelKey(),
            ])
            ->add('accept', CheckboxType::class, [
                'mapped' => false,
                'label' => 'callback.form.accept.label',
                'constraints' => [new IsTrue(message: 'contact.contactForm.accept.label')],
            ])
            // Honeypot: invisible to humans, auto-filled by bots
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
                'constraints' => [new Blank()],
            ])
        ;

        $builder->get('phoneNumber')->addModelTransformer(new PhoneNumberE164Transformer());
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Contact::class,
        ]);
    }
}
