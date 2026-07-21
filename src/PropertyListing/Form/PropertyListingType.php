<?php

namespace App\PropertyListing\Form;

use App\PropertyListing\Domain\Amenity;
use App\PropertyListing\Domain\Furnishing;
use App\PropertyListing\Domain\LeaseType;
use App\PropertyListing\Domain\Orientation;
use App\PropertyListing\Domain\PropertyListingSubmission;
use App\PropertyListing\Domain\PropertyStatus;
use App\PropertyListing\Domain\PropertyType;
use App\Shared\Form\DataTransformer\PhoneNumberE164Transformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Blank;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Range;

class PropertyListingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('fullName', TextType::class, [
                'label' => 'listProperty.form.fullName.label',
                'constraints' => [
                    new NotBlank(
                        message: 'listProperty.form.fullName.notBlank',
                    ),
                    new Length(
                        max: 255,
                        maxMessage: 'listProperty.form.fullName.length',
                    ),
                ],
                'attr' => [
                    'autocomplete' => 'name',
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'listProperty.form.email.label',
                'constraints' => [
                    new NotBlank(
                        message: 'listProperty.form.email.notBlank',
                    ),
                    new Email(
                        message: 'listProperty.form.email.invalid',
                    ),
                ],
                'attr' => [
                    'autocomplete' => 'email',
                ],
            ])
            ->add('phoneNumber', TelType::class, [
                'label' => 'listProperty.form.phoneNumber.label',
                'invalid_message' => 'listProperty.form.phoneNumber.invalidFormat',
                'constraints' => [
                    new NotBlank(
                        message: 'listProperty.form.phoneNumber.notBlank',
                    ),
                ],
            ])
            ->add('address', TextType::class, [
                'label' => 'listProperty.form.address.label',
                'constraints' => [
                    new NotBlank(
                        message: 'listProperty.form.address.notBlank',
                    ),
                    new Length(
                        max: 255,
                        maxMessage: 'listProperty.form.address.length',
                    ),
                ],
                'attr' => [
                    'data-places-autocomplete-target' => 'input',
                    'data-action' => 'input->places-autocomplete#search keydown->places-autocomplete#navigate',
                    'autocomplete' => 'off',
                    'role' => 'combobox',
                    'aria-autocomplete' => 'list',
                    'aria-expanded' => 'false',
                ],
            ])
            // Proof that the address was picked from the Google suggestions
            // list (set by JS on selection, cleared on manual typing).
            ->add('placeId', HiddenType::class, [
                'constraints' => [
                    new NotBlank(
                        message: 'listProperty.form.address.notSelected',
                    ),
                    new Length(
                        max: 255,
                        maxMessage: 'listProperty.form.address.notSelected',
                    ),
                ],
            ])
            ->add('propertyType', EnumType::class, [
                'class' => PropertyType::class,
                'label' => 'listProperty.form.propertyType.label',
                'expanded' => true,
                'multiple' => false,
                'choice_label' => static fn (PropertyType $type): string => 'listProperty.form.propertyType.choice.'.$type->value,
                'constraints' => [
                    new NotBlank(
                        message: 'listProperty.form.propertyType.notBlank',
                    ),
                ],
            ])
            ->add('propertyStatus', EnumType::class, [
                'class' => PropertyStatus::class,
                'label' => 'listProperty.form.propertyStatus.label',
                'expanded' => true,
                'multiple' => false,
                'choice_label' => static fn (PropertyStatus $status): string => 'listProperty.form.propertyStatus.choice.'.$status->value,
                'constraints' => [
                    new NotBlank(
                        message: 'listProperty.form.propertyStatus.notBlank',
                    ),
                ],
            ])
            // Quick-pick chips: the top value is an open bucket ("5+" / "4+"),
            // stored as its lower bound.
            ->add('bedrooms', ChoiceType::class, [
                'label' => 'listProperty.form.bedrooms.label',
                'choices' => ['0' => 0, '1' => 1, '2' => 2, '3' => 3, '4' => 4, '5+' => 5],
                'expanded' => true,
                'multiple' => false,
                'data' => 1,
                'constraints' => [
                    new NotNull(message: 'listProperty.form.bedrooms.notBlank'),
                ],
            ])
            ->add('bathrooms', ChoiceType::class, [
                'label' => 'listProperty.form.bathrooms.label',
                'choices' => ['1' => 1, '2' => 2, '3' => 3, '4+' => 4],
                'expanded' => true,
                'multiple' => false,
                'data' => 1,
                'constraints' => [
                    new NotNull(message: 'listProperty.form.bathrooms.notBlank'),
                ],
            ])
            ->add('surface', IntegerType::class, [
                'label' => 'listProperty.form.surface.label',
                'data' => 30,
                'constraints' => [
                    new NotBlank(message: 'listProperty.form.surface.notBlank'),
                    new Range(notInRangeMessage: 'listProperty.form.surface.range', min: 8, max: 900),
                ],
                'attr' => ['min' => 8, 'max' => 900, 'step' => 1, 'inputmode' => 'numeric'],
            ])
            ->add('furnishing', EnumType::class, [
                'class' => Furnishing::class,
                'label' => 'listProperty.form.furnishing.label',
                'expanded' => true,
                'multiple' => false,
                'choice_label' => static fn (Furnishing $furnishing): string => 'listProperty.form.furnishing.choice.'.$furnishing->value,
                'constraints' => [
                    new NotBlank(
                        message: 'listProperty.form.furnishing.notBlank',
                    ),
                ],
            ])
            ->add('floor', IntegerType::class, [
                'label' => 'listProperty.form.floor.label',
                'data' => 1,
                'required' => false,
                'constraints' => [
                    new Range(notInRangeMessage: 'listProperty.form.floor.range', min: 0, max: 20),
                ],
                'attr' => ['min' => 0, 'max' => 20, 'step' => 1, 'inputmode' => 'numeric'],
            ])
            ->add('buildingFloors', IntegerType::class, [
                'label' => 'listProperty.form.buildingFloors.label',
                'data' => 6,
                'required' => false,
                'constraints' => [
                    new Range(notInRangeMessage: 'listProperty.form.buildingFloors.range', min: 1, max: 20),
                ],
                'attr' => ['min' => 1, 'max' => 20, 'step' => 1, 'inputmode' => 'numeric'],
            ])
            ->add('orientations', EnumType::class, [
                'class' => Orientation::class,
                'label' => 'listProperty.form.orientations.label',
                'expanded' => true,
                'multiple' => true,
                'choice_label' => static fn (Orientation $orientation): string => 'listProperty.form.orientations.choice.'.$orientation->value,
                'constraints' => [
                    new Count(
                        min: 1,
                        minMessage: 'listProperty.form.orientations.notBlank',
                    ),
                ],
            ])
            ->add('leaseTypes', EnumType::class, [
                'class' => LeaseType::class,
                'label' => 'listProperty.form.leaseType.label',
                'expanded' => true,
                'multiple' => true,
                'choice_label' => static fn (LeaseType $leaseType): string => 'listProperty.form.leaseType.choice.'.$leaseType->value,
                'constraints' => [
                    new Count(
                        min: 1,
                        minMessage: 'listProperty.form.leaseType.notBlank',
                    ),
                ],
            ])
            // The financial trio is skipped for an Airbnb-only project: the
            // "required unless Airbnb only" rule lives in the submission DTO
            // (Assert\Callback), Range still applies when a value is given.
            ->add('rent', IntegerType::class, [
                'label' => 'listProperty.form.rent.label',
                'required' => false,
                'constraints' => [
                    new Range(notInRangeMessage: 'listProperty.form.rent.range', min: 1, max: 50000),
                ],
                'attr' => ['min' => 1, 'max' => 50000, 'step' => 1, 'inputmode' => 'numeric'],
            ])
            ->add('charges', IntegerType::class, [
                'label' => 'listProperty.form.charges.label',
                'required' => false,
                'constraints' => [
                    new Range(notInRangeMessage: 'listProperty.form.charges.range', min: 0, max: 10000),
                ],
                'attr' => ['min' => 0, 'max' => 10000, 'step' => 1, 'inputmode' => 'numeric'],
            ])
            ->add('deposit', IntegerType::class, [
                'label' => 'listProperty.form.deposit.label',
                'required' => false,
                'constraints' => [
                    new Range(notInRangeMessage: 'listProperty.form.deposit.range', min: 0, max: 200000),
                ],
                'attr' => ['min' => 0, 'max' => 200000, 'step' => 1, 'inputmode' => 'numeric'],
            ])
            // Optional: a property can have no listed amenity at all.
            ->add('amenities', EnumType::class, [
                'class' => Amenity::class,
                'label' => 'listProperty.form.amenities.label',
                'expanded' => true,
                'multiple' => true,
                'required' => false,
                'choice_label' => static fn (Amenity $amenity): string => 'listProperty.form.amenities.choice.'.$amenity->value,
            ])
            ->add('note', TextareaType::class, [
                'label' => 'listProperty.form.note.label',
                'required' => false,
                'constraints' => [
                    new Length(
                        max: 2000,
                        maxMessage: 'listProperty.form.note.length',
                    ),
                ],
                'attr' => [
                    'rows' => 4,
                    'maxlength' => 2000,
                ],
            ])
            ->add('photos', FileType::class, [
                'label' => 'listProperty.form.photos.label',
                'mapped' => false,
                'required' => false,
                'multiple' => true,
                'constraints' => [
                    new Count(
                        max: 15,
                        maxMessage: 'listProperty.form.photos.tooMany',
                    ),
                    new All([
                        new Image(
                            maxSize: '8M',
                            mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
                            mimeTypesMessage: 'listProperty.form.photos.mimeTypes',
                            maxSizeMessage: 'listProperty.form.photos.maxSize',
                            // A photo over upload_max_filesize never reaches
                            // the maxSize check: PHP flags it first, and the
                            // default message talks in bytes. Same wording,
                            // in MB.
                            uploadIniSizeErrorMessage: 'listProperty.form.photos.maxSize',
                        ),
                    ]),
                ],
                'attr' => [
                    // HEIC/HEIF (iPhone) are accepted at pick time and
                    // converted to JPEG client-side; the server-side Image
                    // constraint above never sees them.
                    'accept' => 'image/jpeg,image/png,image/webp,image/heic,image/heif',
                ],
            ])
            // Honeypot: invisible to humans, frequently auto-filled by bots.
            ->add('website', TextType::class, [
                'mapped' => false,
                'required' => false,
                'label' => false,
                'attr' => [
                    'autocomplete' => 'off',
                    'tabindex' => -1,
                    'aria-hidden' => 'true',
                    'class' => 'sr-only',
                ],
                'constraints' => [
                    new Blank(),
                ],
            ])
        ;

        $builder->get('phoneNumber')->addModelTransformer(new PhoneNumberE164Transformer());
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PropertyListingSubmission::class,
        ]);
    }
}
