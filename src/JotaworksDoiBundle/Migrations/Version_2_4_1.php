<?php

declare(strict_types=1);

namespace MauticPlugin\JotaworksDoiBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

/**
 * 2.4.1: optional contact field per one-click rule ("Leadfeld setzen").
 */
class Version_2_4_1 extends AbstractMigration
{
    private string $table = 'jw_doi_unsubscribe_rule';

    protected function isApplicable(Schema $schema): bool
    {
        $table = $this->concatPrefix($this->table);

        return $schema->hasTable($table) && !$schema->getTable($table)->hasColumn('field_alias');
    }

    protected function up(): void
    {
        $table = $this->concatPrefix($this->table);
        $this->addSql("ALTER TABLE {$table}
            ADD field_alias VARCHAR(191) DEFAULT '' NOT NULL,
            ADD field_value VARCHAR(191) DEFAULT '' NOT NULL");
    }
}
