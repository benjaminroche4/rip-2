<?php

declare(strict_types=1);

namespace App\RealEstateAgent\Form;

use App\RealEstateAgent\Domain\AgencyPosition;
use App\RealEstateAgent\Domain\AgentSpecialty;
use App\RealEstateAgent\Entity\RealEstateAgent;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * "New agent" modal form: identity plus optional agency and contact details.
 */
class RealEstateAgentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', TextType::class, [
                'label' => 'admin.agents.create.firstName.label',
                'attr' => ['maxlength' => 50, 'autocomplete' => 'off'],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'admin.agents.create.lastName.label',
                'attr' => ['maxlength' => 50, 'autocomplete' => 'off'],
            ])
            // Asked first in the modal: the agency field only shows (and is
            // required) when the agent works for one.
            ->add('kind', ChoiceType::class, [
                'label' => 'admin.agents.create.kind.label',
                'mapped' => false,
                'expanded' => true,
                'multiple' => false,
                'placeholder' => false,
                'data' => 'agency',
                'choices' => [
                    'admin.agents.create.kind.agency' => 'agency',
                    'admin.agents.create.kind.independent' => 'independent',
                ],
            ])
            // Unmapped free-text: AgentCreate resolves it to an Agency
            // entity (find-or-create, case-insensitive) when kind = agency.
            ->add('agencyName', TextType::class, [
                'label' => 'admin.agents.create.agency.label',
                'required' => false,
                'mapped' => false,
                'constraints' => [
                    new Assert\Length(max: 100, maxMessage: 'admin.agents.create.agency.length'),
                ],
                'attr' => ['maxlength' => 100, 'autocomplete' => 'off'],
            ])
            // Only meaningful (and only shown) for agency agents; the
            // component resets it to null for an independent agent.
            ->add('position', EnumType::class, [
                'label' => 'admin.agents.create.position.label',
                'class' => AgencyPosition::class,
                'required' => false,
                'expanded' => true,
                'multiple' => false,
                'placeholder' => false,
                'choice_label' => static fn (AgencyPosition $position): string => $position->labelKey(),
            ])
            // Checkboxes rendered as chips: an agent can do both.
            ->add('specialties', EnumType::class, [
                'label' => 'admin.agents.create.specialties.label',
                'class' => AgentSpecialty::class,
                'required' => false,
                'expanded' => true,
                'multiple' => true,
                'choice_label' => static fn (AgentSpecialty $specialty): string => $specialty->labelKey(),
            ])
            ->add('email', EmailType::class, [
                'label' => 'admin.agents.create.email.label',
                'required' => false,
                'attr' => ['maxlength' => 180, 'autocomplete' => 'off'],
            ])
            ->add('phone', TelType::class, [
                'label' => 'admin.agents.create.phone.label',
                'required' => false,
                'attr' => ['maxlength' => 30, 'autocomplete' => 'off', 'placeholder' => '6 12 34 56 78'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RealEstateAgent::class,
            'translation_domain' => 'messages',
            // Live handles CSRF at the component level.
            'csrf_protection' => false,
        ]);
    }
}
