<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration to create bank_account table
 */
final class Version20260115223807_CreateBankAccount extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create bank_account table with swift, iban, bank_account_number, bank_name fields and foreign key to business';
    }

    public function up(Schema $schema): void
    {
        // Create bank_account table
        $this->addSql('CREATE SEQUENCE bank_account_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE bank_account (
            id INT NOT NULL DEFAULT nextval(\'bank_account_id_seq\'),
            business_id INT NOT NULL,
            swift VARCHAR(11) DEFAULT NULL,
            iban VARCHAR(34) DEFAULT NULL,
            bank_account_number VARCHAR(255) DEFAULT NULL,
            bank_name VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');

        // Create foreign key constraint
        $this->addSql('ALTER TABLE bank_account ADD CONSTRAINT FK_bank_account_business FOREIGN KEY (business_id) REFERENCES business (id) ON DELETE CASCADE');

        // Create indexes
        $this->addSql('CREATE INDEX IDX_bank_account_business ON bank_account (business_id)');
    }

    public function down(Schema $schema): void
    {
        // Drop indexes
        $this->addSql('DROP INDEX IF EXISTS IDX_bank_account_business');

        // Drop foreign key constraints
        $this->addSql('ALTER TABLE bank_account DROP CONSTRAINT IF EXISTS FK_bank_account_business');

        // Drop bank_account table
        if ($schema->hasTable('bank_account')) {
            $this->addSql('DROP SEQUENCE IF EXISTS bank_account_id_seq CASCADE');
            $this->addSql('DROP TABLE bank_account');
        }
    }
}
