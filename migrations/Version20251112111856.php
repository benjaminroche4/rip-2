<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251112111856 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE blog (id INT AUTO_INCREMENT NOT NULL, redactor_id INT NOT NULL, slug_fr VARCHAR(255) NOT NULL, slug_en VARCHAR(255) NOT NULL, title_fr VARCHAR(255) NOT NULL, title_en VARCHAR(255) NOT NULL, content_fr LONGTEXT NOT NULL, content_en LONGTEXT NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', is_visible TINYINT(1) NOT NULL, INDEX IDX_C01551438E706861 (redactor_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE blog_blog_category (blog_id INT NOT NULL, blog_category_id INT NOT NULL, INDEX IDX_197F78ADAE07E97 (blog_id), INDEX IDX_197F78ACB76011C (blog_category_id), PRIMARY KEY(blog_id, blog_category_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE blog ADD CONSTRAINT FK_C01551438E706861 FOREIGN KEY (redactor_id) REFERENCES blog_redactor (id)');
        $this->addSql('ALTER TABLE blog_blog_category ADD CONSTRAINT FK_197F78ADAE07E97 FOREIGN KEY (blog_id) REFERENCES blog (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE blog_blog_category ADD CONSTRAINT FK_197F78ACB76011C FOREIGN KEY (blog_category_id) REFERENCES blog_category (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE blog DROP FOREIGN KEY FK_C01551438E706861');
        $this->addSql('ALTER TABLE blog_blog_category DROP FOREIGN KEY FK_197F78ADAE07E97');
        $this->addSql('ALTER TABLE blog_blog_category DROP FOREIGN KEY FK_197F78ACB76011C');
        $this->addSql('DROP TABLE blog');
        $this->addSql('DROP TABLE blog_blog_category');
    }
}
