<?php

declare(strict_types=1);

namespace App\RealEstateAgent\Form;

use App\RealEstateAgent\Entity\Agency;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * "New agency" modal form: just the name. The unique-name check lives in
 * AgencyCreate (case-insensitive) so a duplicate is reported as a field
 * error rather than a database exception.
 */
class AgencyType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'admin.agencies.create.name.label',
            'attr' => ['maxlength' => 100, 'autocomplete' => 'off'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Agency::class,
            // The Live component handles CSRF for the whole form.
            'csrf_protection' => false,
        ]);
    }
}
