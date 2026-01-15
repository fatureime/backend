<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration to make bank_account_number NOT NULL
 */
final class Version20260115230840_MakeBankAccountNumberRequired extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make bank_account_number column NOT NULL in bank_account table';
    }

    public function up(Schema $schema): void
    {
        // First, set a default value for any existing NULL values (empty string)
        $this->addSql("UPDATE bank_account SET bank_account_number = '' WHERE bank_account_number IS NULL");
        
        // Then alter the column to NOT NULL
        $this->addSql('ALTER TABLE bank_account ALTER COLUMN bank_account_number SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // Revert column back to nullable
        $this->addSql('ALTER TABLE bank_account ALTER COLUMN bank_account_number DROP NOT NULL');
    }
}
