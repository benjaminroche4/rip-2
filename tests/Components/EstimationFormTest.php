<?php

namespace App\Tests\Components;

use App\PropertyEstimation\Entity\PropertyEstimation;
use App\PropertyEstimation\Form\PropertyEstimationType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Renders PropertyEstimation/EstimationForm with the real PropertyEstimationType
 * form view to lock in:
 * - the form is wired with Turbo (`data-turbo` attribute)
 * - all expected fields are present (address, propertyCondition, bedroom,
 *   bathroom, surface, phoneNumber, email)
 * - the three number-stepper blocks generate the right Stimulus wiring
 *
 * This catches drift between the form type and its template — e.g. someone
 * adds/removes a field on the entity without updating the component.
 */
final class EstimationFormTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testRendersAllEntityFieldsAndStimulusBindings(): void
    {
        self::bootKernel();
        $formFactory = self::getContainer()->get(FormFactoryInterface::class);
        $form = $formFactory->create(PropertyEstimationType::class, new PropertyEstimation());

        $html = (string) $this->renderTwigComponent('PropertyEstimation:EstimationForm', [
            'form' => $form->createView(),
        ]);

        // Form attributes & Turbo wiring
        $this->assertStringContainsString('data-turbo="true"', $html);
        $this->assertStringContainsString('id="estimation-form"', $html);
        $this->assertStringContainsString('novalidate="novalidate"', $html);

        // All seven expected form widgets
        foreach (['address', 'propertyCondition', 'bedroom', 'bathroom', 'surface', 'phoneNumber', 'email'] as $field) {
            $this->assertMatchesRegularExpression(
                '/(name|id)="property_estimation(_form)?\[?' . preg_quote($field, '/') . '\]?"|(name|id)="property_estimation_' . preg_quote($field, '/') . '"/i',
                $html,
                "Missing widget for field {$field}",
            );
        }

        // Three number-stepper blocks (bedroom + bathroom + surface)
        $this->assertSame(3, substr_count($html, 'data-controller="number-stepper"'));
    }
}
