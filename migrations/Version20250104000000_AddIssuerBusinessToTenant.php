<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration to add issuer_business_id to tenant table
 */
final class Version20250104000000_AddIssuerBusinessToTenant extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add issuer_business_id column to tenant table (unique, NOT NULL, foreign key to business)';
    }

    public function up(Schema $schema): void
    {
        // Add issuer_business_id column (nullable initially for existing data)
        $this->addSql('ALTER TABLE tenant ADD COLUMN issuer_business_id INT');

        // Set issuer_business_id for existing tenants (use first business by created_at)
        $this->addSql("
            UPDATE tenant t
            SET issuer_business_id = (
                SELECT b.id
                FROM business b
                WHERE b.tenant_id = t.id
                ORDER BY b.created_at ASC
                LIMIT 1
            )
            WHERE EXISTS (
                SELECT 1 FROM business b WHERE b.tenant_id = t.id
            )
        ");

        // Create businesses for tenants that don't have any (if any exist)
        // This should not happen based on current logic, but handle it just in case
        $this->addSql("
            INSERT INTO business (
                business_name, created_by_id, tenant_id, created_at, updated_at
            )
            SELECT 
                t.name || ' Business',
                (SELECT u.id FROM \"user\" u WHERE u.tenant_id = t.id LIMIT 1),
                t.id,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            FROM tenant t
            WHERE t.issuer_business_id IS NULL
            AND EXISTS (SELECT 1 FROM \"user\" u WHERE u.tenant_id = t.id)
        ");

        // Update issuer_business_id for newly created businesses
        $this->addSql("
            UPDATE tenant t
            SET issuer_business_id = (
                SELECT b.id
                FROM business b
                WHERE b.tenant_id = t.id
                ORDER BY b.created_at ASC
                LIMIT 1
            )
            WHERE t.issuer_business_id IS NULL
        ");

        // Make issuer_business_id NOT NULL
        $this->addSql('ALTER TABLE tenant ALTER COLUMN issuer_business_id SET NOT NULL');

        // Create foreign key constraint
        $this->addSql('ALTER TABLE tenant ADD CONSTRAINT FK_tenant_issuer_business FOREIGN KEY (issuer_business_id) REFERENCES business (id) ON DELETE RESTRICT');

        // Create unique constraint (ensures one issuer per tenant)
        $this->addSql('CREATE UNIQUE INDEX IDX_tenant_issuer_business ON tenant (issuer_business_id)');
    }

    public function down(Schema $schema): void
    {
        // Drop unique index
        $this->addSql('DROP INDEX IF EXISTS IDX_tenant_issuer_business');

        // Drop foreign key constraint
        $this->addSql('ALTER TABLE tenant DROP CONSTRAINT IF EXISTS FK_tenant_issuer_business');

        // Drop column
        $this->addSql('ALTER TABLE tenant DROP COLUMN issuer_business_id');
    }
}
