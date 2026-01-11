<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration to create invoice table
 */
final class Version20250107000000_CreateInvoice extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create invoice table with issuer_id, receiver_id, invoice_number, dates, status, subtotal/total (no tax fields), and unique constraint on (issuer_id, invoice_number)';
    }

    public function up(Schema $schema): void
    {
        // Create invoice table
        $this->addSql('CREATE SEQUENCE invoice_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE invoice (
            id INT NOT NULL DEFAULT nextval(\'invoice_id_seq\'),
            issuer_id INT NOT NULL,
            receiver_id INT NOT NULL,
            invoice_number VARCHAR(255) NOT NULL,
            invoice_date DATE NOT NULL,
            due_date DATE NOT NULL,
            status VARCHAR(50) NOT NULL DEFAULT \'draft\',
            subtotal NUMERIC(10, 2) NOT NULL DEFAULT 0,
            total NUMERIC(10, 2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');

        // Create foreign key constraints
        $this->addSql('ALTER TABLE invoice ADD CONSTRAINT FK_invoice_issuer FOREIGN KEY (issuer_id) REFERENCES business (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE invoice ADD CONSTRAINT FK_invoice_receiver FOREIGN KEY (receiver_id) REFERENCES business (id) ON DELETE CASCADE');

        // Create unique constraint on (issuer_id, invoice_number) for sequential numbering
        $this->addSql('CREATE UNIQUE INDEX unique_invoice_number_per_issuer ON invoice (issuer_id, invoice_number)');

        // Create indexes
        $this->addSql('CREATE INDEX IDX_invoice_issuer ON invoice (issuer_id)');
        $this->addSql('CREATE INDEX IDX_invoice_receiver ON invoice (receiver_id)');
        $this->addSql('CREATE INDEX IDX_invoice_number ON invoice (invoice_number)');
        $this->addSql('CREATE INDEX IDX_invoice_status ON invoice (status)');
    }

    public function down(Schema $schema): void
    {
        // Drop indexes
        $this->addSql('DROP INDEX IF EXISTS IDX_invoice_status');
        $this->addSql('DROP INDEX IF EXISTS IDX_invoice_number');
        $this->addSql('DROP INDEX IF EXISTS IDX_invoice_receiver');
        $this->addSql('DROP INDEX IF EXISTS IDX_invoice_issuer');
        $this->addSql('DROP INDEX IF EXISTS unique_invoice_number_per_issuer');

        // Drop foreign key constraints
        $this->addSql('ALTER TABLE invoice DROP CONSTRAINT IF EXISTS FK_invoice_receiver');
        $this->addSql('ALTER TABLE invoice DROP CONSTRAINT IF EXISTS FK_invoice_issuer');

        // Drop invoice table
        if ($schema->hasTable('invoice')) {
            $this->addSql('DROP SEQUENCE IF EXISTS invoice_id_seq CASCADE');
            $this->addSql('DROP TABLE invoice');
        }
    }
}
