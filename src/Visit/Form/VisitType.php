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
    public function __construct(private readonly DossierRepository $dossiers)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('dossier', EntityType::class, [
                'class' => Dossier::class,
                'label' => 'admin.visits.create.dossier.label',
                'placeholder' => 'admin.visits.create.dossier.placeholder',
                // Open dossiers with a complete search only: no visit on a
                // closed dossier, and none before the criteria are validated
                // (the submit-time guard keeps rejecting forged ids). The
                // preselected dossier (arrival from a dossier page) stays in
                // the list even incomplete, so the explanatory banner and the
                // search link can render.
                'choices' => $this->dossierChoices($options['preselected_dossier']),
                'choice_label' => static fn (Dossier $d): string => $d->getName().' ('.$d->getReference().')',
                // Editing an existing visit: the dossier is locked (changing
                // it would be another visit). Disabled = the submitted value
                // is ignored server-side, the stored dossier always wins.
                'disabled' => true === $options['editing'],
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
                // now"); form-level on purpose, and lifted in edit mode: a
                // past visit must stay editable (note, address...). Moving
                // the slot INTO the past is still refused by the edit-mode
                // guard in VisitForm::create().
                'constraints' => true === $options['editing'] ? [] : [
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
            // ── "Le bien en détail": optional property facts, chips fed by
            // LiveActions (hidden inputs), never required. ──
            ->add('surface', \Symfony\Component\Form\Extension\Core\Type\NumberType::class, [
                'label' => 'admin.visits.create.propertyDetails.surface.label',
                'required' => false,
                'html5' => true,
                'scale' => 1,
                'attr' => ['step' => '0.5', 'min' => '0'],
            ])
            ->add('floor', \Symfony\Component\Form\Extension\Core\Type\IntegerType::class, [
                'label' => 'admin.visits.create.propertyDetails.floor.label',
                'required' => false,
                'attr' => ['min' => '0', 'placeholder' => 'admin.visits.create.propertyDetails.floor.placeholder'],
            ])
            // Same codes as the dossier-search chips: a forged value falls
            // out of the choices and is rejected by the form.
            ->add('propertyKind', \Symfony\Component\Form\Extension\Core\Type\ChoiceType::class, [
                'label' => 'admin.visits.create.propertyDetails.propertyKind.label',
                'required' => false,
                'choices' => array_combine(
                    array_column(\App\PropertyListing\Domain\PropertyType::cases(), 'value'),
                    array_column(\App\PropertyListing\Domain\PropertyType::cases(), 'value'),
                ),
            ])
            ->add('furnishing', \Symfony\Component\Form\Extension\Core\Type\ChoiceType::class, [
                'label' => 'admin.visits.create.propertyDetails.furnishing.label',
                'required' => false,
                'choices' => array_combine(
                    array_column(\App\Contact\Domain\Furnishing::cases(), 'value'),
                    array_column(\App\Contact\Domain\Furnishing::cases(), 'value'),
                ),
            ])
            ->add('leaseType', \Symfony\Component\Form\Extension\Core\Type\EnumType::class, [
                'label' => 'admin.visits.create.propertyDetails.leaseType.label',
                'required' => false,
                'class' => \App\Visit\Domain\LeaseType::class,
            ])
            ->add('rentExcludingCharges', \Symfony\Component\Form\Extension\Core\Type\NumberType::class, [
                'label' => 'admin.visits.create.propertyDetails.rentExcludingCharges.label',
                'required' => false,
                'html5' => true,
                'scale' => 2,
                'attr' => ['step' => '0.01', 'min' => '0'],
            ])
            ->add('charges', \Symfony\Component\Form\Extension\Core\Type\NumberType::class, [
                'label' => 'admin.visits.create.propertyDetails.charges.label',
                'required' => false,
                'html5' => true,
                'scale' => 2,
                'attr' => ['step' => '0.01', 'min' => '0'],
            ])
            // Loyer CC ou HC : les chips du formulaire pilotent l'input caché
            // ('1' = charges comprises).
            ->add('rentChargesIncluded', \Symfony\Component\Form\Extension\Core\Type\CheckboxType::class, [
                'label' => 'admin.visits.create.propertyDetails.rentMode.cc',
                'required' => false,
                // L'input caché soumet toujours la clé : sans ceci, la chaîne
                // vide (mode HC) serait interprétée comme cochée (le défaut de
                // CheckboxType ne tient que null pour faux) et le mode
                // resterait verrouillé sur "Charges comprises".
                'false_values' => [null, '', '0'],
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
            'preselected_dossier' => null,
            // "Modifier la visite": same form, dossier locked and past-slot
            // constraints lifted (see VisitForm::create()). The entity-level
            // past guard lives in the visit_create group, applied here only
            // outside edit mode.
            'editing' => false,
            'validation_groups' => static fn (\Symfony\Component\Form\FormInterface $form): array => true === $form->getConfig()->getOption('editing')
                ? ['Default']
                : ['Default', 'visit_create'],
        ]);
        $resolver->setAllowedTypes('preselected_dossier', [Dossier::class, 'null']);
        $resolver->setAllowedTypes('editing', 'bool');
    }

    /**
     * @return list<Dossier>
     */
    private function dossierChoices(?Dossier $preselected): array
    {
        $choices = $this->dossiers->findOpenWithCompleteSearch();
        if ($preselected instanceof Dossier
            && !\in_array($preselected->getId(), array_map(static fn (Dossier $d): ?int => $d->getId(), $choices), true)) {
            $choices[] = $preselected;
        }

        return $choices;
    }
}
