<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260111225252 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make issuer_business_id nullable to avoid circular dependency';
    }

    public function up(Schema $schema): void
    {
        // Make issuer_business_id nullable to allow creating tenant without business first
        $this->addSql('ALTER TABLE tenant ALTER COLUMN issuer_business_id DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // Revert: make issuer_business_id NOT NULL again
        // Note: This will fail if there are any NULL values
        $this->addSql('ALTER TABLE tenant ALTER COLUMN issuer_business_id SET NOT NULL');
    }
}
