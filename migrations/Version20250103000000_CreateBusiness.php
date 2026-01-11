<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration to create business table
 */
final class Version20250103000000_CreateBusiness extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create business table with all business fields, created_by_id and tenant_id foreign keys';
    }

    public function up(Schema $schema): void
    {
        // Create business table
        $this->addSql('CREATE SEQUENCE business_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE business (
            id INT NOT NULL DEFAULT nextval(\'business_id_seq\'),
            business_name VARCHAR(255) NOT NULL,
            trade_name VARCHAR(255) DEFAULT NULL,
            business_type VARCHAR(255) DEFAULT NULL,
            unique_identifier_number VARCHAR(255) DEFAULT NULL,
            business_number VARCHAR(255) DEFAULT NULL,
            fiscal_number VARCHAR(255) DEFAULT NULL,
            number_of_employees INT DEFAULT NULL,
            registration_date DATE DEFAULT NULL,
            municipality VARCHAR(255) DEFAULT NULL,
            address TEXT DEFAULT NULL,
            phone VARCHAR(255) DEFAULT NULL,
            email VARCHAR(255) DEFAULT NULL,
            capital NUMERIC(10, 2) DEFAULT NULL,
            arbk_status VARCHAR(255) DEFAULT NULL,
            created_by_id INT NOT NULL,
            tenant_id INT NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');

        // Create foreign key constraints
        $this->addSql('ALTER TABLE business ADD CONSTRAINT FK_business_created_by FOREIGN KEY (created_by_id) REFERENCES "user" (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE business ADD CONSTRAINT FK_business_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE');

        // Create indexes
        $this->addSql('CREATE INDEX IDX_business_created_by ON business (created_by_id)');
        $this->addSql('CREATE INDEX IDX_business_tenant ON business (tenant_id)');
        $this->addSql('CREATE INDEX IDX_business_name ON business (business_name)');
        $this->addSql('CREATE INDEX IDX_business_fiscal_number ON business (fiscal_number)');
    }

    public function down(Schema $schema): void
    {
        // Drop indexes
        $this->addSql('DROP INDEX IF EXISTS IDX_business_fiscal_number');
        $this->addSql('DROP INDEX IF EXISTS IDX_business_name');
        $this->addSql('DROP INDEX IF EXISTS IDX_business_tenant');
        $this->addSql('DROP INDEX IF EXISTS IDX_business_created_by');

        // Drop foreign key constraints
        $this->addSql('ALTER TABLE business DROP CONSTRAINT IF EXISTS FK_business_tenant');
        $this->addSql('ALTER TABLE business DROP CONSTRAINT IF EXISTS FK_business_created_by');

        // Drop business table
        if ($schema->hasTable('business')) {
            $this->addSql('DROP SEQUENCE IF EXISTS business_id_seq CASCADE');
            $this->addSql('DROP TABLE business');
        }
    }
}
