<?php

declare(strict_types=1);

namespace App\Visit\Form;

use App\Dossier\Entity\Dossier;
use App\Dossier\Repository\DossierRepository;
use App\RealEstateAgent\Entity\RealEstateAgent;
use App\RealEstateAgent\Repository\RealEstateAgentRepository;
use App\Visit\Entity\Visit;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * "New visit" modal form: the dossier being helped, when, where, and
 * optionally which real-estate agent shows the property.
 */
class VisitType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('dossier', EntityType::class, [
                'class' => Dossier::class,
                'label' => 'admin.visits.create.dossier.label',
                'placeholder' => 'admin.visits.create.dossier.placeholder',
                // Only open dossiers: no visit is scheduled on a closed one.
                'query_builder' => static fn (DossierRepository $r) => $r->createQueryBuilder('d')
                    ->where('d.closedAt IS NULL')
                    ->orderBy('d.name', 'ASC'),
                'choice_label' => static fn (Dossier $d): string => $d->getName().' ('.$d->getReference().')',
            ])
            ->add('agent', EntityType::class, [
                'class' => RealEstateAgent::class,
                'label' => 'admin.visits.create.agent.label',
                'placeholder' => 'admin.visits.create.agent.placeholder',
                'required' => false,
                'query_builder' => static fn (RealEstateAgentRepository $r) => $r->createQueryBuilder('a')
                    ->orderBy('a.lastName', 'ASC')
                    ->addOrderBy('a.firstName', 'ASC'),
                'choice_label' => static function (RealEstateAgent $a): string {
                    $name = trim($a->getFirstName().' '.$a->getLastName());
                    $agency = $a->getAgency()?->getName();

                    return null !== $agency ? $name.' ('.$agency.')' : $name;
                },
            ])
            ->add('scheduledAt', DateTimeType::class, [
                'label' => 'admin.visits.create.scheduledAt.label',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                // Everything lives in Paris local time, storage included.
                'model_timezone' => 'Europe/Paris',
                'view_timezone' => 'Europe/Paris',
            ])
            ->add('address', TextType::class, [
                'label' => 'admin.visits.create.address.label',
                'attr' => [
                    'maxlength' => 255,
                    'autocomplete' => 'off',
                    'placeholder' => 'admin.visits.create.address.placeholder',
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Visit::class,
            'translation_domain' => 'messages',
            // Live handles CSRF at the component level.
            'csrf_protection' => false,
        ]);
    }
}
