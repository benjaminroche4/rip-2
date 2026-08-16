<?php

declare(strict_types=1);

namespace App\RealEstateAgent\Form;

use App\RealEstateAgent\Domain\AgentSpecialty;
use App\RealEstateAgent\Entity\Agency;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * "New agency" page form: the name plus optional brand, address and
 * contact details. The unique-name check lives in AgencyCreate
 * (case-insensitive) so a duplicate is reported as a field error rather
 * than a database exception.
 */
class AgencyType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'admin.agencies.create.name.label',
                'attr' => ['maxlength' => 100, 'autocomplete' => 'off'],
            ])
            // Unmapped free-text: AgencyCreate resolves it to a Brand
            // entity (find-or-create, case-insensitive) when filled in.
            ->add('brandName', TextType::class, [
                'label' => 'admin.agencies.create.brand.label',
                'required' => false,
                'mapped' => false,
                'constraints' => [
                    new Assert\Length(max: 100, maxMessage: 'admin.agencies.create.brand.length'),
                ],
                'attr' => ['maxlength' => 100, 'autocomplete' => 'off'],
            ])
            ->add('address', TextType::class, [
                'label' => 'admin.agencies.create.address.label',
                'required' => false,
                'attr' => ['maxlength' => 255, 'autocomplete' => 'off'],
            ])
            ->add('phone', TelType::class, [
                'label' => 'admin.agencies.create.phone.label',
                'required' => false,
                'attr' => ['maxlength' => 30, 'autocomplete' => 'off', 'placeholder' => '6 12 34 56 78'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'admin.agencies.create.email.label',
                'required' => false,
                'attr' => ['maxlength' => 180, 'autocomplete' => 'off'],
            ])
            // Free text normalised by the entity setter (trim + https://
            // prefix when no scheme was typed).
            ->add('website', TextType::class, [
                'label' => 'admin.agencies.create.website.label',
                'required' => false,
                'attr' => ['maxlength' => 255, 'autocomplete' => 'off', 'placeholder' => 'admin.agencies.create.website.placeholder'],
            ])
            // Checkboxes rendered as chips: an agency can do both.
            ->add('specialties', EnumType::class, [
                'label' => 'admin.agents.create.specialties.label',
                'class' => AgentSpecialty::class,
                'required' => false,
                'expanded' => true,
                'multiple' => true,
                'choice_label' => static fn (AgentSpecialty $specialty): string => $specialty->labelKey(),
            ])
            // Team-only free note, never shown outside the back office.
            ->add('note', TextareaType::class, [
                'label' => 'admin.agencies.create.note.label',
                'required' => false,
                'attr' => ['maxlength' => 2000, 'autocomplete' => 'off', 'placeholder' => 'admin.agencies.create.note.placeholder'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Agency::class,
            // The Live component handles CSRF for the whole form.
            'csrf_protection' => false,
        ]);
    }
}
