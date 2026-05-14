<?php

namespace App\Auth\Form\Register;

use App\Auth\Domain\Register\RegisterDto;
use Symfony\Component\Form\Flow\AbstractFlowType;
use Symfony\Component\Form\Flow\FormFlowBuilderInterface;
use Symfony\Component\Form\Flow\Type\NavigatorFlowType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Multi-step registration FormFlow (Symfony 8 native).
 *
 * Step 1 collects identity (firstName, lastName, email) under validation group "personal".
 * Step 2 collects the password and terms acceptance under group "account".
 *
 * Intermediate data is persisted via the bundled SessionDataStorage (default in 8.0), so a
 * page reload between steps preserves the user's input. Validation groups are derived from
 * the current step name by FormFlowType.
 */
final class RegisterFlowType extends AbstractFlowType
{
    public function buildFormFlow(FormFlowBuilderInterface $builder, array $options): void
    {
        $builder->addStep('personal', PersonalStepType::class);
        $builder->addStep('account', AccountStepType::class);

        $builder->add('navigator', NavigatorFlowType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RegisterDto::class,
            'step_property_path' => 'currentStep',
        ]);
    }
}
