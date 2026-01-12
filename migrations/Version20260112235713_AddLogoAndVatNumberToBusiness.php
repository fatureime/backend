<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration to add logo and vat_number fields to business table
 */
final class Version20260112235713_AddLogoAndVatNumberToBusiness extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add logo and vat_number columns to business table';
    }

    public function up(Schema $schema): void
    {
        // Add logo column (stores file path)
        $this->addSql('ALTER TABLE business ADD COLUMN logo VARCHAR(255) DEFAULT NULL');
        
        // Add vat_number column
        $this->addSql('ALTER TABLE business ADD COLUMN vat_number VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Remove columns
        $this->addSql('ALTER TABLE business DROP COLUMN IF EXISTS logo');
        $this->addSql('ALTER TABLE business DROP COLUMN IF EXISTS vat_number');
    }
}
