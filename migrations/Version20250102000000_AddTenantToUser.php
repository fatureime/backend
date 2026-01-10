<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration to create tenant table and add tenant_id and is_active to user table
 */
final class Version20250102000000_AddTenantToUser extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create tenant table and add tenant_id (NOT NULL foreign key) and is_active column to user table';
    }

    public function up(Schema $schema): void
    {
        // Create tenant table first (if it doesn't exist)
        if (!$schema->hasTable('tenant')) {
            $this->addSql('CREATE SEQUENCE tenant_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
            $this->addSql('CREATE TABLE tenant (
                id INT NOT NULL DEFAULT nextval(\'tenant_id_seq\'),
                name VARCHAR(255) NOT NULL,
                has_paid BOOLEAN NOT NULL DEFAULT false,
                is_admin BOOLEAN NOT NULL DEFAULT false,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )');
        }

        // Add is_active column first (nullable initially, then set default and make NOT NULL)
        $this->addSql('ALTER TABLE "user" ADD COLUMN is_active BOOLEAN');
        $this->addSql('UPDATE "user" SET is_active = true WHERE is_active IS NULL');
        $this->addSql('ALTER TABLE "user" ALTER COLUMN is_active SET NOT NULL');
        $this->addSql('ALTER TABLE "user" ALTER COLUMN is_active SET DEFAULT true');

        // Create a default tenant for existing users if needed
        // Note: This assumes there might be existing users. If no users exist, this is safe.
        $this->addSql("
            INSERT INTO tenant (id, name, has_paid, is_admin, created_at, updated_at)
            SELECT 1, 'Default Tenant', false, false, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            WHERE NOT EXISTS (SELECT 1 FROM tenant WHERE id = 1)
        ");

        // Add tenant_id column (nullable initially to allow assignment)
        $this->addSql('ALTER TABLE "user" ADD COLUMN tenant_id INT');
        $this->addSql('UPDATE "user" SET tenant_id = 1 WHERE tenant_id IS NULL');
        $this->addSql('ALTER TABLE "user" ALTER COLUMN tenant_id SET NOT NULL');
        
        // Create foreign key constraint
        $this->addSql('ALTER TABLE "user" ADD CONSTRAINT FK_user_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_user_tenant ON "user" (tenant_id)');
    }

    public function down(Schema $schema): void
    {
        // Remove foreign key and columns from user table
        $this->addSql('ALTER TABLE "user" DROP CONSTRAINT IF EXISTS FK_user_tenant');
        $this->addSql('DROP INDEX IF EXISTS IDX_user_tenant');
        $this->addSql('ALTER TABLE "user" DROP COLUMN tenant_id');
        $this->addSql('ALTER TABLE "user" DROP COLUMN is_active');

        // Drop tenant table
        if ($schema->hasTable('tenant')) {
            $this->addSql('DROP SEQUENCE IF EXISTS tenant_id_seq CASCADE');
            $this->addSql('DROP TABLE tenant');
        }
    }
}
