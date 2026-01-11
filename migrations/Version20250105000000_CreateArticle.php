<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration to create article table
 */
final class Version20250105000000_CreateArticle extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create article table with business_id foreign key and all article fields';
    }

    public function up(Schema $schema): void
    {
        // Create article table
        $this->addSql('CREATE SEQUENCE article_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE article (
            id INT NOT NULL DEFAULT nextval(\'article_id_seq\'),
            business_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT DEFAULT NULL,
            unit_price NUMERIC(10, 2) NOT NULL,
            unit VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');

        // Create foreign key constraint
        $this->addSql('ALTER TABLE article ADD CONSTRAINT FK_article_business FOREIGN KEY (business_id) REFERENCES business (id) ON DELETE CASCADE');

        // Create indexes
        $this->addSql('CREATE INDEX IDX_article_business ON article (business_id)');
        $this->addSql('CREATE INDEX IDX_article_name ON article (name)');
    }

    public function down(Schema $schema): void
    {
        // Drop indexes
        $this->addSql('DROP INDEX IF EXISTS IDX_article_name');
        $this->addSql('DROP INDEX IF EXISTS IDX_article_business');

        // Drop foreign key constraint
        $this->addSql('ALTER TABLE article DROP CONSTRAINT IF EXISTS FK_article_business');

        // Drop article table
        if ($schema->hasTable('article')) {
            $this->addSql('DROP SEQUENCE IF EXISTS article_id_seq CASCADE');
            $this->addSql('DROP TABLE article');
        }
    }
}
