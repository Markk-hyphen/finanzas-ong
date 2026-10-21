<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261021131210 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Confirmation modes (MANIFIESTO E1): project settings, payment confirmation trail, cancellation kind, instance heartbeats';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE instance_heartbeats (name TEXT NOT NULL, beat_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (name))');
        $this->addSql('ALTER TABLE movements ADD cancellation_kind TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE movements ADD confirmed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE movements ADD confirmed_via TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE movements ADD auto_confirm_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE movements ADD confirmed_by_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE movements ADD CONSTRAINT FK_382375216F45385D FOREIGN KEY (confirmed_by_id) REFERENCES members (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_382375216F45385D ON movements (confirmed_by_id)');
        $this->addSql('ALTER TABLE projects ADD confirmation_mode TEXT DEFAULT \'required\' NOT NULL');
        $this->addSql('ALTER TABLE projects ADD objection_window_hours INT DEFAULT 48 NOT NULL');
        $this->addSql('ALTER TABLE projects ADD self_cancel_minutes INT DEFAULT 15 NOT NULL');

        // Backfill: before E1 only the admin could cancel and only the receiver could
        // confirm. The confirmation time of old payments is unknown and stays null.
        $this->addSql("UPDATE movements SET cancellation_kind = 'admin' WHERE type = 'cancellation'");
        $this->addSql("UPDATE movements SET confirmed_via = 'receiver' WHERE type = 'payment' AND status = 'confirmed'");

        // Shape rules Doctrine cannot express (same approach as Version20260616145224)
        $this->addSql("ALTER TABLE movements ADD CONSTRAINT chk_cancellation_kind CHECK ((type = 'cancellation') = (cancellation_kind IS NOT NULL) AND (cancellation_kind IS NULL OR cancellation_kind IN ('admin', 'withdrawal', 'self_correction', 'rejection')))");
        $this->addSql("ALTER TABLE movements ADD CONSTRAINT chk_confirmed_via CHECK (confirmed_via IS NULL OR (type = 'payment' AND confirmed_via IN ('receiver', 'optimistic', 'timeout')))");
        $this->addSql("ALTER TABLE movements ADD CONSTRAINT chk_auto_confirm_only_payments CHECK (auto_confirm_at IS NULL OR type = 'payment')");
        $this->addSql("ALTER TABLE projects ADD CONSTRAINT chk_confirmation_mode CHECK (confirmation_mode IN ('required', 'optimistic', 'auto_timeout'))");
        $this->addSql('ALTER TABLE projects ADD CONSTRAINT chk_objection_window CHECK (objection_window_hours BETWEEN 1 AND 720)');
        $this->addSql('ALTER TABLE projects ADD CONSTRAINT chk_self_cancel_minutes CHECK (self_cancel_minutes BETWEEN 0 AND 1440)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE projects DROP CONSTRAINT chk_self_cancel_minutes');
        $this->addSql('ALTER TABLE projects DROP CONSTRAINT chk_objection_window');
        $this->addSql('ALTER TABLE projects DROP CONSTRAINT chk_confirmation_mode');
        $this->addSql('ALTER TABLE movements DROP CONSTRAINT chk_auto_confirm_only_payments');
        $this->addSql('ALTER TABLE movements DROP CONSTRAINT chk_confirmed_via');
        $this->addSql('ALTER TABLE movements DROP CONSTRAINT chk_cancellation_kind');
        $this->addSql('DROP TABLE instance_heartbeats');
        $this->addSql('ALTER TABLE movements DROP CONSTRAINT FK_382375216F45385D');
        $this->addSql('DROP INDEX IDX_382375216F45385D');
        $this->addSql('ALTER TABLE movements DROP cancellation_kind');
        $this->addSql('ALTER TABLE movements DROP confirmed_at');
        $this->addSql('ALTER TABLE movements DROP confirmed_via');
        $this->addSql('ALTER TABLE movements DROP auto_confirm_at');
        $this->addSql('ALTER TABLE movements DROP confirmed_by_id');
        $this->addSql('ALTER TABLE projects DROP confirmation_mode');
        $this->addSql('ALTER TABLE projects DROP objection_window_hours');
        $this->addSql('ALTER TABLE projects DROP self_cancel_minutes');
    }
}
