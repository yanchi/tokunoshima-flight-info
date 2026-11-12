<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112033800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Timetable storage per direction with its fetch record (docs/data-source-verification.md §7 step 7)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE timetable_fetch_record (
              direction VARCHAR(16) NOT NULL,
              request_started_at DATETIME NOT NULL,
              latest_data_date DATETIME NOT NULL,
              record_count INTEGER NOT NULL,
              PRIMARY KEY (direction)
            )
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE timetable_flight (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              direction VARCHAR(16) NOT NULL,
              flight_number VARCHAR(8) NOT NULL,
              origin_airport VARCHAR(3) NOT NULL,
              destination_airport VARCHAR(3) NOT NULL,
              weekday SMALLINT NOT NULL,
              origin_time VARCHAR(5) NOT NULL,
              destination_time VARCHAR(5) NOT NULL,
              origin_day_difference INTEGER DEFAULT NULL,
              destination_day_difference INTEGER DEFAULT NULL,
              valid_from DATETIME NOT NULL,
              valid_to DATETIME NOT NULL,
              note CLOB DEFAULT NULL,
              airline VARCHAR(255) NOT NULL
            )
        SQL);
        $this->addSql('CREATE INDEX idx_timetable_flight_direction ON timetable_flight (direction)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE timetable_fetch_record');
        $this->addSql('DROP TABLE timetable_flight');
    }
}
