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
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

final readonly class DoctrineTimetableDataPurger implements TimetableDataPurgerInterface
{
    /**
     * Rows referring to a gate version go before the version. Every mapped
     * entity must be listed (DoctrineTimetableDataPurgerTest checks this),
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

        // Managed entities would still hold the deleted rows.
        $this->entityManager->clear();

        // DELETE leaves the rows' bytes in free pages of the file. VACUUM
        // rewrites the file without them; it cannot run inside a transaction.
        // The default rollback journal is removed when it ends (no WAL is
        // configured, config/packages/doctrine.yaml).
        if (!$connection->getDatabasePlatform() instanceof SQLitePlatform) {
            return new TimetablePurgeResult($deleted, false);
        }
        try {
            $connection->executeStatement('VACUUM');
        } catch (\Throwable $e) {
            throw new TimetableSpaceNotReclaimed($deleted, $e);
        }

        return new TimetablePurgeResult($deleted, true);
    }
}
