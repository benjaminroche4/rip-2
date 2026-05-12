<?php

declare(strict_types=1);

namespace App\Admin\Form;

use App\Admin\Domain\DocumentCategory;
use App\Admin\Entity\Document;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Admin form for creating a Document. Slug and createdAt are intentionally
 * absent — they are filled by DocumentSlugger and the LiveComponent action
 * at persist time. All field-level constraints live on the entity itself
 * (Assert\…), so this type only declares the UI shape; the validator
 * picks the constraints up through auto-mapping.
 */
class DocumentFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('category', EnumType::class, [
                'class' => DocumentCategory::class,
                'choice_label' => fn (DocumentCategory $c): string => $c->labelKey(),
                'expanded' => true,
                'multiple' => false,
                'placeholder' => false,
                'label' => 'admin.tools.documents.form.category.label',
            ])
            ->add('nameFr', TextType::class, [
                'label' => 'admin.tools.documents.form.nameFr.label',
                'attr' => ['maxlength' => 255, 'autocomplete' => 'off'],
            ])
            ->add('nameEn', TextType::class, [
                'label' => 'admin.tools.documents.form.nameEn.label',
                'attr' => ['maxlength' => 255, 'autocomplete' => 'off'],
            ])
            ->add('descriptionFr', TextareaType::class, [
                'label' => 'admin.tools.documents.form.descriptionFr.label',
                'required' => false,
                'attr' => ['maxlength' => 5000, 'rows' => 4],
            ])
            ->add('descriptionEn', TextareaType::class, [
                'label' => 'admin.tools.documents.form.descriptionEn.label',
                'required' => false,
                'attr' => ['maxlength' => 5000, 'rows' => 4],
            ])
            ->add('pinned', CheckboxType::class, [
                'label' => 'admin.tools.documents.form.pinned.label',
                'help' => 'admin.tools.documents.form.pinned.help',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Document::class,
            'translation_domain' => 'messages',
            // Live components ship a component-level CSRF token (rendered as
            // data-live-csrf-value on the host element). Form-level CSRF would
            // add a redundant hidden _token field we'd then have to render
            // manually to avoid duplicating the visible fields via form_rest.
            'csrf_protection' => false,
        ]);
    }
}
