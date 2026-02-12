<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260212212437 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE blog DROP FOREIGN KEY FK_C01551438E706861');
        $this->addSql('ALTER TABLE blog_blog_category DROP FOREIGN KEY FK_197F78ACB76011C');
        $this->addSql('ALTER TABLE blog_blog_category DROP FOREIGN KEY FK_197F78ADAE07E97');
        $this->addSql('DROP TABLE blog');
        $this->addSql('DROP TABLE blog_redactor');
        $this->addSql('DROP TABLE blog_category');
        $this->addSql('DROP TABLE blog_blog_category');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE blog (id INT AUTO_INCREMENT NOT NULL, redactor_id INT NOT NULL, slug_fr VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, slug_en VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, title_fr VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, title_en VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, content_fr LONGTEXT CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, content_en LONGTEXT CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', is_visible TINYINT(1) NOT NULL, main_photo VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, short_desc_fr VARCHAR(160) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, main_photo_alt VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, short_desc_en VARCHAR(160) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, time_to_read INT NOT NULL, INDEX IDX_C01551438E706861 (redactor_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE blog_redactor (id INT AUTO_INCREMENT NOT NULL, full_name VARCHAR(30) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, bio_fr VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, bio_en VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, photo VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE blog_category (id INT AUTO_INCREMENT NOT NULL, slug_fr VARCHAR(120) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, slug_en VARCHAR(120) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, title_fr VARCHAR(120) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, title_en VARCHAR(120) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE blog_blog_category (blog_id INT NOT NULL, blog_category_id INT NOT NULL, INDEX IDX_197F78ACB76011C (blog_category_id), INDEX IDX_197F78ADAE07E97 (blog_id), PRIMARY KEY(blog_id, blog_category_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE blog ADD CONSTRAINT FK_C01551438E706861 FOREIGN KEY (redactor_id) REFERENCES blog_redactor (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('ALTER TABLE blog_blog_category ADD CONSTRAINT FK_197F78ACB76011C FOREIGN KEY (blog_category_id) REFERENCES blog_category (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE blog_blog_category ADD CONSTRAINT FK_197F78ADAE07E97 FOREIGN KEY (blog_id) REFERENCES blog (id) ON UPDATE NO ACTION ON DELETE CASCADE');
    }
}
