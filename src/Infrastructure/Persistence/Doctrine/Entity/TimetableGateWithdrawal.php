<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine\Entity;

use App\Application\Gate\WithdrawalCause;
use App\Domain\Flight\Direction;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A direction withdrawn from one gate settings version (§7 step 7). Tied to
 * the version it was judged against, so a newer version starts with no
 * withdrawals. At most one per version and direction; the operator's
 * manual operations are kept apart in TimetableGateManualWithdrawal.
 */
#[ORM\Entity]
#[ORM\Table(name: 'timetable_gate_withdrawal')]
#[ORM\UniqueConstraint(name: 'uniq_timetable_gate_withdrawal_version_direction', columns: ['version_id', 'direction'])]
class TimetableGateWithdrawal
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
        #[ORM\Column(length: 32, enumType: WithdrawalCause::class)]
        private WithdrawalCause $cause,
        #[ORM\Column(type: Types::DATETIME_UTC_IMMUTABLE)]
        private \DateTimeImmutable $withdrawnAt,
    ) {
    }

    public function direction(): Direction
    {
        return $this->direction;
    }
}
