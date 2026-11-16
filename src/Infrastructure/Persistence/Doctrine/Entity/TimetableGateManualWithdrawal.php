<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine\Entity;

use App\Domain\Flight\Direction;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One manual withdrawal operation by the operator (§7 step 7 「手動で外す操作」).
 * Every operation is kept, also one on a direction already withdrawn.
 */
#[ORM\Entity]
#[ORM\Table(name: 'timetable_gate_manual_withdrawal')]
class TimetableGateManualWithdrawal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: TimetableGateVersionRecord::class)]
        #[ORM\JoinColumn(name: 'version_id', nullable: false)]
        private TimetableGateVersionRecord $version,
        #[ORM\Column(length: 16, enumType: Direction::class)]
        private Direction $direction,
        #[ORM\Column(type: Types::TEXT)]
        private string $reason,
        #[ORM\Column(type: Types::DATETIME_UTC_IMMUTABLE)]
        private \DateTimeImmutable $executedAt,
    ) {
    }
}
