<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine;

use App\Application\Port\TimetableDataPurgerInterface;
use App\Application\Purge\TimetablePurgeResult;
use App\Application\Purge\TimetableSpaceNotReclaimed;
use App\Infrastructure\Persistence\Doctrine\Entity\TimetableFetchRecord;
use App\Infrastructure\Persistence\Doctrine\Entity\TimetableFlight;
use App\Infrastructure\Persistence\Doctrine\Entity\TimetableGateManualWithdrawal;
use App\Infrastructure\Persistence\Doctrine\Entity\TimetableGateVersionRecord;
use App\Infrastructure\Persistence\Doctrine\Entity\TimetableGateWithdrawal;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

final readonly class DoctrineTimetableDataPurger implements TimetableDataPurgerInterface
{
    /**
     * Rows referring to a gate version go before the version. Every mapped
     * entity must be listed (PurgeTimetableDataCommandTest::
     * testEveryMappedEntityIsPurged checks this),
     * so a table added later is not left out by mistake.
     */
    public const ENTITIES = [
        TimetableGateManualWithdrawal::class,
        TimetableGateWithdrawal::class,
        TimetableGateVersionRecord::class,
        TimetableFlight::class,
        TimetableFetchRecord::class,
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ManagerRegistry $registry,
    ) {
    }

    public function purgeAll(): TimetablePurgeResult
    {
        $connection = $this->entityManager->getConnection();
        $tables = array_map(fn (string $entity): string => $this->entityManager->getClassMetadata($entity)->getTableName(), self::ENTITIES);
        $isSqlite = $connection->getDatabasePlatform() instanceof SQLitePlatform;

        try {
            /** @var array<string, int> $deleted */
            $deleted = $this->entityManager->wrapInTransaction(static function () use ($connection, $tables): array {
                $deleted = [];
                foreach ($tables as $table) {
                    $deleted[$table] = (int) $connection->executeStatement('DELETE FROM '.$connection->quoteSingleIdentifier($table));
                }

                return $deleted;
            });
        } catch (\Throwable $e) {
            if (!$this->entityManager->isOpen()) {
                $this->registry->resetManager();
            }

            throw $e;
        }

        // From here on the rows are deleted: whatever fails is reported as
        // such, never as "nothing was deleted".
        try {
            // Managed entities would still hold the deleted rows.
            $this->entityManager->clear();
            if (!$isSqlite) {
                return new TimetablePurgeResult($deleted, false);
            }
            $this->rewriteSqliteFile($connection);
        } catch (\Throwable $e) {
            throw new TimetableSpaceNotReclaimed($deleted, $e);
        }

        return new TimetablePurgeResult($deleted, true);
    }

    /**
     * DELETE leaves the rows' bytes in free pages of the file. VACUUM
     * rewrites the file without them; it cannot run inside a transaction.
     * The journal mode is stored in the database file, not in the
     * configuration, so it is read here: in WAL mode the rewritten pages go
     * to the -wal file first and the old ones stay in the main file until a
     * checkpoint copies them back.
     */
    private function rewriteSqliteFile(Connection $connection): void
    {
        $connection->executeStatement('VACUUM');
        $journalMode = $connection->fetchOne('PRAGMA journal_mode');
        if (!\is_string($journalMode)) {
            throw new \RuntimeException('The journal mode could not be read.');
        }
        if ('wal' !== strtolower($journalMode)) {
            return;
        }

        // Columns: busy, log frames, checkpointed frames. TRUNCATE also
        // empties the -wal file.
        $checkpoint = $connection->fetchNumeric('PRAGMA wal_checkpoint(TRUNCATE)');
        if (false === $checkpoint || !\in_array($checkpoint[0] ?? null, [0, '0'], true)) {
            throw new \RuntimeException('The WAL checkpoint did not complete.');
        }
    }
}
