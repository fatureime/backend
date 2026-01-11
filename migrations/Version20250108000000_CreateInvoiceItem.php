<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration to create invoice_item table
 */
final class Version20250108000000_CreateInvoiceItem extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create invoice_item table with invoice_id, article_id, tax_id, quantity, prices, tax_amount, subtotal, total, and cascade delete on invoice_id';
    }

    public function up(Schema $schema): void
    {
        // Create invoice_item table
        $this->addSql('CREATE SEQUENCE invoice_item_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE invoice_item (
            id INT NOT NULL DEFAULT nextval(\'invoice_item_id_seq\'),
            invoice_id INT NOT NULL,
            article_id INT DEFAULT NULL,
            tax_id INT DEFAULT NULL,
            description VARCHAR(255) NOT NULL,
            quantity NUMERIC(10, 2) NOT NULL,
            unit_price NUMERIC(10, 2) NOT NULL,
            subtotal NUMERIC(10, 2) NOT NULL DEFAULT 0,
            tax_amount NUMERIC(10, 2) NOT NULL DEFAULT 0,
            total NUMERIC(10, 2) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');

        // Create foreign key constraints
        $this->addSql('ALTER TABLE invoice_item ADD CONSTRAINT FK_invoice_item_invoice FOREIGN KEY (invoice_id) REFERENCES invoice (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE invoice_item ADD CONSTRAINT FK_invoice_item_article FOREIGN KEY (article_id) REFERENCES article (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE invoice_item ADD CONSTRAINT FK_invoice_item_tax FOREIGN KEY (tax_id) REFERENCES tax (id) ON DELETE SET NULL');

        // Create indexes
        $this->addSql('CREATE INDEX IDX_invoice_item_invoice ON invoice_item (invoice_id)');
        $this->addSql('CREATE INDEX IDX_invoice_item_article ON invoice_item (article_id)');
        $this->addSql('CREATE INDEX IDX_invoice_item_tax ON invoice_item (tax_id)');
        $this->addSql('CREATE INDEX IDX_invoice_item_sort_order ON invoice_item (sort_order)');
    }

    public function down(Schema $schema): void
    {
        // Drop indexes
        $this->addSql('DROP INDEX IF EXISTS IDX_invoice_item_sort_order');
        $this->addSql('DROP INDEX IF EXISTS IDX_invoice_item_tax');
        $this->addSql('DROP INDEX IF EXISTS IDX_invoice_item_article');
        $this->addSql('DROP INDEX IF EXISTS IDX_invoice_item_invoice');

        // Drop foreign key constraints
        $this->addSql('ALTER TABLE invoice_item DROP CONSTRAINT IF EXISTS FK_invoice_item_tax');
        $this->addSql('ALTER TABLE invoice_item DROP CONSTRAINT IF EXISTS FK_invoice_item_article');
        $this->addSql('ALTER TABLE invoice_item DROP CONSTRAINT IF EXISTS FK_invoice_item_invoice');

        // Drop invoice_item table
        if ($schema->hasTable('invoice_item')) {
            $this->addSql('DROP SEQUENCE IF EXISTS invoice_item_id_seq CASCADE');
            $this->addSql('DROP TABLE invoice_item');
        }
    }
}
