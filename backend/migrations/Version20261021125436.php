<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261021125436 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Unique cancellation per movement (replaces the plain FK index on cancels_movement_id)';
    }

    public function up(Schema $schema): void
    {
        // The ledger is append-only: duplicate cancellations (from concurrent cancel
        // requests before this index existed) are not deleted here — resolve them by
        // hand and re-run.
        $duplicates = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM (SELECT cancels_movement_id FROM movements
              WHERE cancels_movement_id IS NOT NULL
              GROUP BY cancels_movement_id HAVING COUNT(*) > 1) d'
        );
        $this->abortIf(
            $duplicates > 0,
            "{$duplicates} movement(s) have more than one cancellation row; resolve them before adding the unique index.",
        );

        $this->addSql('DROP INDEX idx_38237521ef5accb6');
        $this->addSql('CREATE UNIQUE INDEX uniq_movements_cancels_movement ON movements (cancels_movement_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_movements_cancels_movement');
        $this->addSql('CREATE INDEX idx_38237521ef5accb6 ON movements (cancels_movement_id)');
    }
}
