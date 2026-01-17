<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration to create invoice_status table and migrate invoice status from string to entity
 */
final class Version20260116000000_CreateInvoiceStatus extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create invoice_status table, seed status data, migrate existing invoices, and update schema';
    }

    public function up(Schema $schema): void
    {
        // Create invoice_status table
        $this->addSql('CREATE SEQUENCE invoice_status_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE invoice_status (
            id INT NOT NULL DEFAULT nextval(\'invoice_status_id_seq\'),
            code VARCHAR(50) NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE UNIQUE INDEX unique_invoice_status_code ON invoice_status (code)');

        // Seed initial data for 5 statuses
        $this->addSql("INSERT INTO invoice_status (code, created_at, updated_at) VALUES ('draft', NOW(), NOW())");
        $this->addSql("INSERT INTO invoice_status (code, created_at, updated_at) VALUES ('sent', NOW(), NOW())");
        $this->addSql("INSERT INTO invoice_status (code, created_at, updated_at) VALUES ('paid', NOW(), NOW())");
        $this->addSql("INSERT INTO invoice_status (code, created_at, updated_at) VALUES ('overdue', NOW(), NOW())");
        $this->addSql("INSERT INTO invoice_status (code, created_at, updated_at) VALUES ('cancelled', NOW(), NOW())");

        // Add status_id column to invoice table (nullable initially)
        $this->addSql('ALTER TABLE invoice ADD COLUMN status_id INT DEFAULT NULL');

        // Migrate existing data: update status_id based on current status string values
        $this->addSql("UPDATE invoice SET status_id = (SELECT id FROM invoice_status WHERE code = invoice.status) WHERE status IS NOT NULL");

        // Make status_id NOT NULL (after migration)
        $this->addSql('ALTER TABLE invoice ALTER COLUMN status_id SET NOT NULL');

        // Add foreign key constraint
        $this->addSql('ALTER TABLE invoice ADD CONSTRAINT FK_invoice_status FOREIGN KEY (status_id) REFERENCES invoice_status (id) ON DELETE RESTRICT');

        // Create index on status_id
        $this->addSql('CREATE INDEX IDX_invoice_status_id ON invoice (status_id)');

        // Drop old status column and its index
        $this->addSql('DROP INDEX IF EXISTS IDX_invoice_status');
        $this->addSql('ALTER TABLE invoice DROP COLUMN status');
    }

    public function down(Schema $schema): void
    {
        // Add back status column
        $this->addSql('ALTER TABLE invoice ADD COLUMN status VARCHAR(50) NOT NULL DEFAULT \'draft\'');

        // Migrate data back from status_id to status
        $this->addSql('UPDATE invoice SET status = (SELECT code FROM invoice_status WHERE id = invoice.status_id)');

        // Drop foreign key and index
        $this->addSql('DROP INDEX IF EXISTS IDX_invoice_status_id');
        $this->addSql('ALTER TABLE invoice DROP CONSTRAINT IF EXISTS FK_invoice_status');

        // Drop status_id column
        $this->addSql('ALTER TABLE invoice DROP COLUMN status_id');

        // Recreate old index
        $this->addSql('CREATE INDEX IDX_invoice_status ON invoice (status)');

        // Drop invoice_status table
        if ($schema->hasTable('invoice_status')) {
            $this->addSql('DROP INDEX IF EXISTS unique_invoice_status_code');
            $this->addSql('DROP SEQUENCE IF EXISTS invoice_status_id_seq CASCADE');
            $this->addSql('DROP TABLE invoice_status');
        }
    }
}
