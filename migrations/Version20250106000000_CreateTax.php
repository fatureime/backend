<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration to create tax table with seed data
 */
final class Version20250106000000_CreateTax extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create tax table with rate field and seed data for exempted (null), 0, 8, 19 percent rates';
    }

    public function up(Schema $schema): void
    {
        // Create tax table
        $this->addSql('CREATE SEQUENCE tax_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE tax (
            id INT NOT NULL DEFAULT nextval(\'tax_id_seq\'),
            rate NUMERIC(5, 2) DEFAULT NULL,
            name VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');

        // Create unique constraint on rate (allows one null value)
        $this->addSql('CREATE UNIQUE INDEX unique_tax_rate ON tax (rate)');

        // Seed data: Insert 4 tax records
        $this->addSql("INSERT INTO tax (rate, name, created_at, updated_at) VALUES (NULL, 'Exempted', NOW(), NOW())");
        $this->addSql("INSERT INTO tax (rate, name, created_at, updated_at) VALUES (0, '0%', NOW(), NOW())");
        $this->addSql("INSERT INTO tax (rate, name, created_at, updated_at) VALUES (8, '8%', NOW(), NOW())");
        $this->addSql("INSERT INTO tax (rate, name, created_at, updated_at) VALUES (19, '19%', NOW(), NOW())");
    }

    public function down(Schema $schema): void
    {
        // Drop unique constraint
        $this->addSql('DROP INDEX IF EXISTS unique_tax_rate');

        // Drop tax table
        if ($schema->hasTable('tax')) {
            $this->addSql('DROP SEQUENCE IF EXISTS tax_id_seq CASCADE');
            $this->addSql('DROP TABLE tax');
        }
    }
}
