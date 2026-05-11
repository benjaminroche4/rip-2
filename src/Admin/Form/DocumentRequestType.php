<?php

declare(strict_types=1);

namespace App\Admin\Form;

use App\Admin\Domain\HouseholdTypology;
use App\Admin\Domain\RequestLanguage;
use App\Admin\Entity\DocumentRequest;
use App\Admin\Entity\PersonRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\LiveComponent\Form\Type\LiveCollectionType;

/**
 * Root form for the bilingual document request flow. The `persons` collection
 * is bound to the LiveCollectionTrait's allow_add/allow_delete buttons so the
 * admin can grow/shrink the list dynamically (1..DocumentRequest::MAX_PERSONS).
 */
class DocumentRequestType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('persons', LiveCollectionType::class, [
                'entry_type' => PersonRequestType::class,
                'entry_options' => ['label' => false],
                'allow_add' => true,
                'allow_delete' => true,
                // by_reference must be false so the parent's add/remove methods
                // are called — otherwise the inverse side isn't wired up.
                'by_reference' => false,
                'label' => false,
                'prototype' => true,
                'prototype_data' => new PersonRequest(),
            ])
            ->add('typology', EnumType::class, [
                'class' => HouseholdTypology::class,
                'choice_label' => fn (HouseholdTypology $t): string => $t->labelKey(),
                'expanded' => true,
                'multiple' => false,
                'placeholder' => false,
                'label' => 'admin.tools.documents.request.typology.label',
            ])
            ->add('note', TextareaType::class, [
                'label' => 'admin.tools.documents.request.note.label',
                'required' => false,
                'attr' => ['maxlength' => 2000, 'rows' => 3],
            ])
            ->add('driveLink', UrlType::class, [
                'label' => 'admin.tools.documents.request.driveLink.label',
                'attr' => [
                    'maxlength' => 512,
                    'placeholder' => 'https://drive.google.com/...',
                    'inputmode' => 'url',
                ],
                'default_protocol' => 'https',
            ])
            ->add('language', EnumType::class, [
                'class' => RequestLanguage::class,
                'choice_label' => fn (RequestLanguage $l): string => $l->labelKey(),
                'expanded' => true,
                'multiple' => false,
                'placeholder' => false,
                'label' => 'admin.tools.documents.request.language.label',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DocumentRequest::class,
            'translation_domain' => 'messages',
            'csrf_protection' => false,
        ]);
    }
}
