<?php

declare(strict_types=1);

namespace App\Auth\Form\Register;

use App\Auth\Domain\Register\VerificationCodeSubmission;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form bound to {@see VerificationCodeSubmission}. The visible UI is rendered as
 * six individual <input> boxes (one per digit) wired by the Stimulus
 * `otp-input` controller; this form type only declares the single `code` string
 * that the controller concatenates and submits.
 */
final class VerificationCodeFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // The visible UI is 6 individual digit inputs (otp-input Stimulus controller).
        // The actual form field is a single hidden input that the controller keeps in
        // sync with the visible boxes, so Symfony only sees a 6-digit string at submit.
        //
        // error_bubbling defaults to true on HiddenType — turn it off so per-field
        // errors (invalid code, expired, etc.) stay on form.code and the template can
        // render the message next to the OTP boxes instead of at the form root.
        $builder->add('code', HiddenType::class, [
            'error_bubbling' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => VerificationCodeSubmission::class,
        ]);
    }
}
