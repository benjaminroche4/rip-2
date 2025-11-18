<?php

namespace App\Controller\Admin;

use App\Entity\Blog;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Validator\Constraints\Image;

class BlogCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly SluggerInterface $slugger
    )
    {

    }

    public static function getEntityFqcn(): string
    {
        return Blog::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            TextField::new('titleFr', '🇫🇷 Titre FR'),
            TextField::new('titleEn', '🇬🇧 Titre EN'),
            TextareaField::new('shortDescFr', '🇫🇷 Résumé FR')
                ->setMaxLength(160)
                ->setHelp('Le résumé doit contenir au maximum 160 caractères')
            ,
            TextareaField::new('shortDescEn', '🇬🇧 Résumé EN')
                ->setMaxLength(160)
                ->setHelp('Le résumé doit contenir au maximum 160 caractères')
            ,
            TextEditorField::new('contentFr', '🇫🇷 Contenu de l\'article FR')->setTrixEditorConfig(
                [
                    'blockAttributes' => [
                        'default' => ['tagName' => 'p'],
                        'heading1' => ['tagName' => 'h2'],
                    ],
                ])
            ,
            TextEditorField::new('contentEn', '🇬🇧 Contenu de l\'article EN')->setTrixEditorConfig(
                [
                    'blockAttributes' => [
                        'default' => ['tagName' => 'p'],
                        'heading1' => ['tagName' => 'h2'],
                    ],
                ])
            ,
            ImageField::new('mainPhoto', 'Photo de l\'article')
                ->setUploadDir('public/medias/blog/cover/')
                ->setBasePath('medias/blog/cover/')
                ->setUploadedFileNamePattern('[slug]-[uuid].[extension]')
                ->setFileConstraints(new Image(maxSize: '160K',mimeTypes: ['image/webp']))
                ->setHelp('Max 160K, format webp seulement')
            ,
            TextField::new('mainPhotoAlt', 'Balise ALT photo article'),
            AssociationField::new('category', 'Catégorie')->autocomplete(),
            AssociationField::new('redactor', 'Rédacteur')->autocomplete(),
            BooleanField::new('visible', 'Publié l\'article'),
        ];
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $slugFr = $this->slugger->slug($entityInstance->getTitleFr())->lower();
        $slugEn = $this->slugger->slug($entityInstance->getTitleEn())->lower();

        $entityInstance->setSlugFr($slugFr);
        $entityInstance->setSlugEn($slugEn);

        $entityInstance->setCreatedAt(new \DateTimeImmutable());

        parent::persistEntity($entityManager, $entityInstance);
    }
}
