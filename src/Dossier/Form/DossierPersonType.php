<?php

declare(strict_types=1);

namespace App\Dossier\Form;

use App\Dossier\Domain\ContactLanguage;
use App\Dossier\Domain\DossierPersonRole;
use App\Dossier\Entity\DossierPerson;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Sub-form embedded in DossierType's collection. One person of the dossier:
 * role (tenant / follow-up request), identity and contact language.
 */
class DossierPersonType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('role', EnumType::class, [
                'class' => DossierPersonRole::class,
                'choice_label' => fn (DossierPersonRole $r): string => $r->labelKey(),
                'expanded' => true,
                'multiple' => false,
                'placeholder' => false,
                'label' => 'admin.dossiers.create.person.role.label',
            ])
            ->add('firstName', TextType::class, [
                'label' => 'admin.dossiers.create.person.firstName.label',
                'attr' => ['maxlength' => 50, 'autocomplete' => 'off'],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'admin.dossiers.create.person.lastName.label',
                'attr' => ['maxlength' => 50, 'autocomplete' => 'off'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'admin.dossiers.create.person.email.label',
                'attr' => ['maxlength' => 180, 'autocomplete' => 'off'],
            ])
            ->add('phone', TelType::class, [
                'label' => 'admin.dossiers.create.person.phone.label',
                'required' => false,
                'attr' => ['maxlength' => 30, 'autocomplete' => 'off', 'placeholder' => '6 12 34 56 78'],
            ])
            ->add('language', EnumType::class, [
                'class' => ContactLanguage::class,
                'choice_label' => fn (ContactLanguage $l): string => $l->labelKey(),
                'expanded' => true,
                'multiple' => false,
                'placeholder' => false,
                'label' => 'admin.dossiers.create.person.language.label',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DossierPerson::class,
            'translation_domain' => 'messages',
            // Live handles CSRF at the component level.
            'csrf_protection' => false,
        ]);
    }
}
