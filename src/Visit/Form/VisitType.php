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
            ->add('assignee', EntityType::class, [
                'class' => \App\Auth\Entity\User::class,
                'label' => 'admin.visits.create.assignee.label',
                'placeholder' => 'admin.visits.create.assignee.placeholder',
                'required' => false,
                // The RIP team member doing the visit: staff or admin
                // carrying the "visit agent" business function.
                'query_builder' => static fn (\App\Auth\Repository\UserRepository $r) => $r->createQueryBuilder('u')
                    ->where('u.roles LIKE :admin OR u.roles LIKE :staff')
                    ->andWhere('u.staffFunctions LIKE :fn')
                    ->setParameter('admin', '%ROLE_ADMIN%')
                    ->setParameter('staff', '%ROLE_STAFF%')
                    ->setParameter('fn', '%visit_agent%')
                    ->orderBy('u.firstName', 'ASC')
                    ->addOrderBy('u.lastName', 'ASC'),
                'choice_label' => static fn (\App\Auth\Entity\User $u): string => trim(($u->getFirstName() ?? '').' '.($u->getLastName() ?? '')) ?: (string) $u->getEmail(),
            ])
            ->add('agent', EntityType::class, [
                'class' => RealEstateAgent::class,
                'label' => 'admin.visits.create.agent.label',
                'placeholder' => 'admin.visits.create.agent.placeholder',
                'required' => false,
                // Deactivated agents leave the picker (the directory keeps them).
                'query_builder' => static fn (RealEstateAgentRepository $r) => $r->createQueryBuilder('a')
                    ->where('a.deactivatedAt IS NULL')
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
                // No scheduling in the past (10 min of grace for "right
                // now"); form-level on purpose: editing an already-past
                // visit from its detail page stays possible.
                'constraints' => [
                    new \Symfony\Component\Validator\Constraints\GreaterThan('-10 minutes', message: 'admin.visits.create.scheduledAt.past'),
                ],
            ])
            ->add('type', \Symfony\Component\Form\Extension\Core\Type\EnumType::class, [
                'class' => \App\Visit\Domain\VisitType::class,
                'label' => 'admin.visits.create.type.label',
            ])
            ->add('listingUrl', \Symfony\Component\Form\Extension\Core\Type\UrlType::class, [
                'label' => 'admin.visits.create.listingUrl.label',
                'required' => false,
                'default_protocol' => 'https',
                'attr' => [
                    'maxlength' => 500,
                    'placeholder' => 'admin.visits.create.listingUrl.placeholder',
                ],
            ])
            ->add('durationMinutes', \Symfony\Component\Form\Extension\Core\Type\ChoiceType::class, [
                'label' => 'admin.visits.create.duration.label',
                'choices' => [
                    'admin.visits.create.duration.min15' => 15,
                    'admin.visits.create.duration.min30' => 30,
                    'admin.visits.create.duration.min45' => 45,
                    'admin.visits.create.duration.min60' => 60,
                ],
            ])
            ->add('clientPresent', \Symfony\Component\Form\Extension\Core\Type\CheckboxType::class, [
                'label' => 'admin.visits.create.clientPresent.label',
                'required' => false,
            ])
            ->add('note', \Symfony\Component\Form\Extension\Core\Type\TextareaType::class, [
                'label' => 'admin.visits.create.note.label',
                'required' => false,
                'attr' => [
                    'maxlength' => 2000,
                    'rows' => 3,
                    'placeholder' => 'admin.visits.create.note.placeholder',
                ],
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
