<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\UseCase\SyncTimetable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Run from cron. Exits 1 whenever the operator should look at the sync log
 * (docs/data-source-verification.md §7 step 7 「同期の終了コード」). The details
 * go to the sync log, not to this command's output.
 */
#[AsCommand(name: 'app:timetable:sync', description: 'Fetches and stores the timetable of both directions')]
final class SyncTimetableCommand extends Command
{
    public function __construct(private readonly SyncTimetable $syncTimetable)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->syncTimetable->execute()->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
    }
}
