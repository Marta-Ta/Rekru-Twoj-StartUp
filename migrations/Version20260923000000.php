<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260923000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add rejected_by / rejected_at columns for the RejectSalesDocument operation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sales_document ADD rejected_by INT DEFAULT NULL');
        $this->addSql('ALTER TABLE sales_document ADD rejected_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sales_document DROP rejected_by');
        $this->addSql('ALTER TABLE sales_document DROP rejected_at');
    }
}
