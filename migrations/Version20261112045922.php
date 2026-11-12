<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112045922 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the timetable validity period as JST calendar days (docs/data-source-verification.md §7 step 7)';
    }

    public function up(Schema $schema): void
    {
        // Stored values are UTC instants of +09:00 times at 00:00:00 (from)
        // and 23:59:59 (to), so adding 9 hours gives the JST calendar day.
        $this->addSql('CREATE TEMPORARY TABLE __temp__timetable_flight AS SELECT id, direction, flight_number, origin_airport, destination_airport, weekday, origin_time, destination_time, origin_day_difference, destination_day_difference, valid_from, valid_to, note, airline FROM timetable_flight');
        $this->addSql('DROP TABLE timetable_flight');
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
              valid_from DATE NOT NULL,
              valid_to DATE NOT NULL,
              note CLOB DEFAULT NULL,
              airline VARCHAR(255) NOT NULL
            )
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO timetable_flight (id, direction, flight_number, origin_airport, destination_airport, weekday, origin_time, destination_time, origin_day_difference, destination_day_difference, valid_from, valid_to, note, airline)
            SELECT id, direction, flight_number, origin_airport, destination_airport, weekday, origin_time, destination_time, origin_day_difference, destination_day_difference, date(valid_from, '+9 hours'), date(valid_to, '+9 hours'), note, airline
            FROM __temp__timetable_flight
        SQL);
        $this->addSql('DROP TABLE __temp__timetable_flight');
        $this->addSql('CREATE INDEX idx_timetable_flight_direction ON timetable_flight (direction)');
    }

    public function down(Schema $schema): void
    {
        // Back to the UTC instants of 00:00:00 and 23:59:59 JST.
        $this->addSql('CREATE TEMPORARY TABLE __temp__timetable_flight AS SELECT id, direction, flight_number, origin_airport, destination_airport, weekday, origin_time, destination_time, origin_day_difference, destination_day_difference, valid_from, valid_to, note, airline FROM timetable_flight');
        $this->addSql('DROP TABLE timetable_flight');
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
        $this->addSql(<<<'SQL'
            INSERT INTO timetable_flight (id, direction, flight_number, origin_airport, destination_airport, weekday, origin_time, destination_time, origin_day_difference, destination_day_difference, valid_from, valid_to, note, airline)
            SELECT id, direction, flight_number, origin_airport, destination_airport, weekday, origin_time, destination_time, origin_day_difference, destination_day_difference, datetime(valid_from, '-9 hours'), datetime(valid_to || ' 23:59:59', '-9 hours'), note, airline
            FROM __temp__timetable_flight
        SQL);
        $this->addSql('DROP TABLE __temp__timetable_flight');
        $this->addSql('CREATE INDEX idx_timetable_flight_direction ON timetable_flight (direction)');
    }
}
