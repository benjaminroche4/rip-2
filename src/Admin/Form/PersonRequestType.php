<?php

declare(strict_types=1);

namespace App\Admin\Form;

use App\Admin\Domain\PersonRole;
use App\Admin\Entity\Document;
use App\Admin\Entity\PersonRequest;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Sub-form embedded in DocumentRequestType's CollectionType. Renders one
 * person: first/last name + a multi-checkbox listing the Document catalogue.
 * The expanded EntityType renders one <input type="checkbox"> per doc, with
 * the label being the locale-aware document name (manually composed in the
 * template — we keep choice_label simple here).
 */
class PersonRequestType extends AbstractType
{
    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $locale = $this->requestStack->getCurrentRequest()?->getLocale() ?? 'fr';

        $builder
            ->add('role', EnumType::class, [
                'class' => PersonRole::class,
                'choice_label' => fn (PersonRole $r): string => $r->labelKey(),
                'expanded' => true,
                'multiple' => false,
                'placeholder' => false,
                'label' => 'admin.tools.documents.request.person.role.label',
            ])
            ->add('firstName', TextType::class, [
                'label' => 'admin.tools.documents.request.person.firstName.label',
                'attr' => ['maxlength' => 50, 'autocomplete' => 'off'],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'admin.tools.documents.request.person.lastName.label',
                'attr' => ['maxlength' => 50, 'autocomplete' => 'off'],
            ])
            ->add('documents', EntityType::class, [
                'class' => Document::class,
                'multiple' => true,
                'expanded' => true,
                'choice_label' => fn (Document $doc): string => $doc->getName($locale),
                // Pinned docs first (matches the catalogue ordering), then
                // alphabetical within each bucket — keeps "priority" pieces
                // at the top so the admin spots them while building a request.
                'query_builder' => fn (EntityRepository $r) => $r->createQueryBuilder('d')
                    ->orderBy('d.pinned', 'DESC')
                    ->addOrderBy('d.nameFr', 'ASC'),
                'label' => false,
                'by_reference' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PersonRequest::class,
            'translation_domain' => 'messages',
            // Live handles CSRF at the component level.
            'csrf_protection' => false,
        ]);
    }
}
