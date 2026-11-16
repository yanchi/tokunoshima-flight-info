<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\Port\TimetableLockUnavailable;
use App\Application\Purge\TimetableSpaceNotReclaimed;
use App\Application\UseCase\PurgeTimetableData;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Run by hand by the operator when the ODPT licence ends, after stopping the
 * sync (docs/data-source-verification.md §2 「許諾終了時の削除」). Deletes the
 * timetable, the fetch records and every gate row. Needs --confirm and never
 * asks interactively. What it did is printed here; nothing goes to the sync log.
 */
#[AsCommand(name: 'app:timetable:purge', description: 'Deletes every stored timetable, fetch record and gate row (when the licence ends)')]
final class PurgeTimetableDataCommand extends Command
{
    public function __construct(private readonly PurgeTimetableData $purgeTimetableData)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('confirm', null, InputOption::VALUE_NONE, 'Required: confirms that every stored timetable and gate row is deleted');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (true !== $input->getOption('confirm')) {
            $io->error('Run with --confirm to delete every stored timetable, fetch record and gate row. Nothing was changed.');

            return Command::FAILURE;
        }

        try {
            $result = $this->purgeTimetableData->execute();
        } catch (TimetableLockUnavailable) {
            $io->error('The timetable lock is held by a sync or a gate command; wait until it ends and run again. Nothing was changed.');

            return Command::FAILURE;
        } catch (TimetableSpaceNotReclaimed $e) {
            self::listDeleted($io, $e->deletedRows);
            // The class only, as in the sync: a driver message may quote values.
            $io->error(\sprintf('The rows are deleted, but the database file may still hold their bytes (%s). Run again to rewrite it.', get_debug_type($e->getPrevious())));

            return Command::FAILURE;
        } catch (\Throwable $e) {
            $io->error(\sprintf('The purge failed (%s). The transaction was rolled back; nothing was deleted.', $e::class));

            return Command::FAILURE;
        }

        self::listDeleted($io, $result->deletedRows);
        if (!$result->spaceReclaimed) {
            $io->warning('The rows are deleted, but this database is not SQLite, so the file was not rewritten without them. Reclaim the space by hand.');

            return Command::SUCCESS;
        }
        $io->success('Every stored timetable and gate row is deleted, and the database file is rewritten without them.');

        return Command::SUCCESS;
    }

    /**
     * @param array<string, int> $deletedRows
     */
    private static function listDeleted(SymfonyStyle $io, array $deletedRows): void
    {
        $io->definitionList(...array_map(
            static fn (string $table, int $rows): array => [$table => \sprintf('%d rows deleted', $rows)],
            array_keys($deletedRows),
            $deletedRows,
        ));
    }
}
