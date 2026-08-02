<?php

declare(strict_types=1);

namespace App\Dossier\Form;

use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierPerson;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\LiveComponent\Form\Type\LiveCollectionType;

/**
 * Creation form of a dossier: 1..4 persons (max 2 tenants, max 2 follow-up
 * requests). The dossier name is not a field — DossierCreate derives it from
 * the tenants' last names right before submit. Bound live in the modal.
 */
class DossierType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('persons', LiveCollectionType::class, [
                'entry_type' => DossierPersonType::class,
                'entry_options' => ['label' => false],
                'allow_add' => true,
                'allow_delete' => true,
                // by_reference must be false so the parent's add/remove methods
                // are called — otherwise the inverse side isn't wired up.
                'by_reference' => false,
                'label' => false,
                'prototype' => true,
                'prototype_data' => new DossierPerson(),
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Dossier::class,
            'translation_domain' => 'messages',
            // Live handles CSRF at the component level.
            'csrf_protection' => false,
        ]);
    }
}
