<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260627120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add username to members (derived from email, unique)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE members ADD COLUMN username TEXT");
        $this->addSql("UPDATE members SET username = lower(split_part(email, '@', 1))");
        $this->addSql("ALTER TABLE members ALTER COLUMN username SET NOT NULL");
        $this->addSql('CREATE UNIQUE INDEX UNIQ_45A0D2FFF85E0677 ON members (username)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_45A0D2FFF85E0677');
        $this->addSql('ALTER TABLE members DROP COLUMN username');
    }
}
