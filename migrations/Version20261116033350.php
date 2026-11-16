<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261116033350 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Timetable gate settings versions, the directions withdrawn from them and the manual withdrawal operations (docs/data-source-verification.md §7 step 7)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE timetable_gate_version (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              compared_from DATE NOT NULL,
              compared_to DATE NOT NULL,
              compared_on DATE NOT NULL,
              record_commit VARCHAR(40) NOT NULL,
              departure_data_date DATETIME NOT NULL,
              arrival_data_date DATETIME NOT NULL,
              departure_hash VARCHAR(64) NOT NULL,
              arrival_hash VARCHAR(64) NOT NULL,
              set_at DATETIME NOT NULL
            )
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE timetable_gate_withdrawal (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              direction VARCHAR(16) NOT NULL,
              cause VARCHAR(32) NOT NULL,
              withdrawn_at DATETIME NOT NULL,
              version_id INTEGER NOT NULL,
              CONSTRAINT FK_6DB06F774BBC2705 FOREIGN KEY (version_id) REFERENCES timetable_gate_version (id) NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_timetable_gate_withdrawal_version_direction ON timetable_gate_withdrawal (version_id, direction)');
        $this->addSql('CREATE INDEX IDX_6DB06F774BBC2705 ON timetable_gate_withdrawal (version_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE timetable_gate_manual_withdrawal (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              direction VARCHAR(16) NOT NULL,
              reason CLOB NOT NULL,
              executed_at DATETIME NOT NULL,
              version_id INTEGER NOT NULL,
              CONSTRAINT FK_E4B5DCB14BBC2705 FOREIGN KEY (version_id) REFERENCES timetable_gate_version (id) NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_E4B5DCB14BBC2705 ON timetable_gate_manual_withdrawal (version_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE timetable_gate_manual_withdrawal');
        $this->addSql('DROP TABLE timetable_gate_withdrawal');
        $this->addSql('DROP TABLE timetable_gate_version');
    }
}
