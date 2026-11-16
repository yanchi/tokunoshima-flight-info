<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine;

use App\Application\Gate\TimetableGateSettings;
use App\Application\Gate\TimetableGateState;
use App\Application\Gate\TimetableGateVersion;
use App\Application\Gate\WithdrawalCause;
use App\Application\Port\TimetableGateRepositoryInterface;
use App\Domain\Flight\Direction;
use App\Infrastructure\Persistence\Doctrine\Entity\TimetableGateVersionRecord;
use App\Infrastructure\Persistence\Doctrine\Entity\TimetableGateWithdrawal;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Shares the entity manager with DoctrineTimetableRepository, so a
 * withdrawal written from the sync's save callback joins the transaction
 * that replaces the timetable.
 */
final readonly class DoctrineTimetableGateRepository implements TimetableGateRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ManagerRegistry $registry,
    ) {
    }

    public function transactional(callable $callback): mixed
    {
        try {
            return $this->entityManager->wrapInTransaction(static fn (): mixed => $callback());
        } catch (\Throwable $e) {
            // A failed transaction closes the entity manager; reset it as the
            // timetable repository does.
            if (!$this->entityManager->isOpen()) {
                $this->registry->resetManager();
            }

            throw $e;
        }
    }

    public function findCurrentState(): ?TimetableGateState
    {
        $record = $this->latestRecord();
        if (null === $record) {
            return null;
        }

        /** @var list<TimetableGateWithdrawal> $withdrawals */
        $withdrawals = $this->entityManager->createQueryBuilder()
            ->select('w')
            ->from(TimetableGateWithdrawal::class, 'w')
            ->where('w.version = :version')
            ->setParameter('version', $record->id())
            ->getQuery()
            ->getResult();

        return new TimetableGateState(
            $record->toVersion(),
            array_map(static fn (TimetableGateWithdrawal $w): Direction => $w->direction(), $withdrawals),
        );
    }

    public function addVersion(TimetableGateSettings $settings, \DateTimeImmutable $setAt): TimetableGateVersion
    {
        $record = TimetableGateVersionRecord::fromSettings($settings, $setAt);
        $this->entityManager->persist($record);
        $this->entityManager->flush();

        return $record->toVersion();
    }

    public function withdraw(int $versionId, Direction $direction, WithdrawalCause $cause, ?string $reason, \DateTimeImmutable $withdrawnAt): bool
    {
        $existing = $this->entityManager->getRepository(TimetableGateWithdrawal::class)->count([
            'version' => $versionId,
            'direction' => $direction,
        ]);
        if ($existing > 0) {
            return false;
        }

        // Looked up rather than referenced: SQLite does not enforce the foreign
        // key unless foreign_keys is turned on, which this connection does not.
        $version = $this->entityManager->find(TimetableGateVersionRecord::class, $versionId)
            ?? throw new \LogicException(\sprintf('Gate version %d does not exist.', $versionId));
        $this->entityManager->persist(new TimetableGateWithdrawal($version, $direction, $cause, $reason, $withdrawnAt));
        $this->entityManager->flush();

        return true;
    }

    private function latestRecord(): ?TimetableGateVersionRecord
    {
        return $this->entityManager->getRepository(TimetableGateVersionRecord::class)->findOneBy([], ['id' => 'DESC']);
    }
}
