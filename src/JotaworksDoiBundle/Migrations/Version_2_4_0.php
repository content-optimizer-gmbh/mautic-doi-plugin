<?php

declare(strict_types=1);

namespace MauticPlugin\JotaworksDoiBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

/**
 * 2.4.0: per-email one-click unsubscribe rules. A fresh install creates the table from the entity
 * metadata; this migration covers updates of an installed plugin (mautic:plugins:reload).
 */
class Version_2_4_0 extends AbstractMigration
{
    private string $table = 'jw_doi_unsubscribe_rule';

    protected function isApplicable(Schema $schema): bool
    {
        return !$schema->hasTable($this->concatPrefix($this->table));
    }

    protected function up(): void
    {
        $table = $this->concatPrefix($this->table);
        $this->addSql("CREATE TABLE {$table} (
            email_id INT NOT NULL,
            mode VARCHAR(191) NOT NULL,
            segment_ids JSON NOT NULL,
            fallback VARCHAR(191) NOT NULL,
            date_added DATETIME NOT NULL,
            date_modified DATETIME NOT NULL,
            PRIMARY KEY(email_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB ROW_FORMAT = DYNAMIC");
    }
}
