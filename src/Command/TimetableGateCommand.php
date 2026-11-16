<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\Gate\TimetableGateInputInvalid;
use App\Application\Gate\TimetableGateRefused;
use App\Application\Gate\TimetableGateRequest;
use App\Application\Port\TimetableLockUnavailable;
use App\Application\UseCase\SetTimetableGate;
use App\Application\UseCase\WithdrawalOutcome;
use App\Application\UseCase\WithdrawTimetableDirection;
use App\Domain\Flight\Direction;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Run by hand by the operator (docs/data-source-verification.md §7 step 7
 * 「設定値と外した状態の保存・解除」):
 *
 * - set: after comparing with the official timetable and merging the step 5
 *   record, sets a new gate settings version, which clears every withdrawal;
 * - withdraw: withdraws one direction from the version in force.
 *
 * Exits 1 and changes nothing when an input is missing or malformed, the
 * stored timetable does not match, or the lock is held. What it did is
 * printed here; nothing goes to the sync log.
 */
#[AsCommand(name: 'app:timetable:gate', description: 'Sets the compared timetable period, or withdraws a direction from publication')]
final class TimetableGateCommand extends Command
{
    private const SET_OPTIONS = ['from', 'to', 'compared-on', 'record-commit', 'departure-data-date', 'arrival-data-date'];
    private const WITHDRAW_OPTIONS = ['direction', 'reason'];

    public function __construct(
        private readonly SetTimetableGate $setTimetableGate,
        private readonly WithdrawTimetableDirection $withdrawTimetableDirection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, '"set" or "withdraw"')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'set: first compared day (JST, Y-m-d)')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'set: last compared day, inclusive (JST, Y-m-d)')
            ->addOption('compared-on', null, InputOption::VALUE_REQUIRED, 'set: the day the comparison was made (JST, Y-m-d)')
            ->addOption('record-commit', null, InputOption::VALUE_REQUIRED, 'set: merge commit of the PR with the step 5 record (40 lowercase hex digits)')
            ->addOption('departure-data-date', null, InputOption::VALUE_REQUIRED, 'set: dc:date of the compared TKN departure timetable, with a time zone offset')
            ->addOption('arrival-data-date', null, InputOption::VALUE_REQUIRED, 'set: dc:date of the compared TKN arrival timetable, with a time zone offset')
            ->addOption('direction', null, InputOption::VALUE_REQUIRED, 'withdraw: "departure" or "arrival"')
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'withdraw: why the direction is withdrawn');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            return match ($input->getArgument('action')) {
                'set' => $this->set($input, $io),
                'withdraw' => $this->withdraw($input, $io),
                default => $this->refuse($io, 'The action must be "set" or "withdraw".'),
            };
        } catch (TimetableGateInputInvalid|TimetableGateRefused $e) {
            return $this->refuse($io, $e->getMessage());
        } catch (TimetableLockUnavailable) {
            return $this->refuse($io, 'The timetable lock is held by a sync or another gate command; run again later.');
        } catch (\Throwable $e) {
            // The class only, as in the sync: a driver message may quote values.
            // The transaction has been rolled back.
            return $this->refuse($io, \sprintf('The gate command failed (%s).', $e::class));
        }
    }

    private function set(InputInterface $input, SymfonyStyle $io): int
    {
        // An option of the other action is refused, not ignored: e.g. a
        // "set --direction departure" meant as a withdrawal would clear
        // every withdrawal.
        self::refuseOptions($input, self::WITHDRAW_OPTIONS, 'set');
        $request = TimetableGateRequest::fromStrings(
            self::option($input, 'from'),
            self::option($input, 'to'),
            self::option($input, 'compared-on'),
            self::option($input, 'record-commit'),
            self::option($input, 'departure-data-date'),
            self::option($input, 'arrival-data-date'),
        );

        $version = $this->setTimetableGate->execute($request);
        $settings = $version->settings;
        $io->success(\sprintf('Gate version %d set; every withdrawal is cleared.', $version->id));
        // The hashes go into the step 5 record in a later commit (§7 step 7).
        $io->definitionList(
            ['compared period' => $settings->period->from->toString().' to '.$settings->period->to->toString()],
            ['departure hash' => $settings->departureHash],
            ['arrival hash' => $settings->arrivalHash],
        );

        return Command::SUCCESS;
    }

    private function withdraw(InputInterface $input, SymfonyStyle $io): int
    {
        self::refuseOptions($input, self::SET_OPTIONS, 'withdraw');
        $direction = Direction::tryFrom(self::option($input, 'direction') ?? '');
        if (null === $direction) {
            return $this->refuse($io, 'The direction must be "departure" or "arrival".');
        }

        $reason = self::option($input, 'reason');
        if (null === $reason) {
            return $this->refuse($io, 'A reason is required.');
        }

        return match ($this->withdrawTimetableDirection->execute($direction, $reason)) {
            WithdrawalOutcome::Withdrawn => $this->done($io, \sprintf('The %s timetable is withdrawn until a new gate version is set.', $direction->value)),
            WithdrawalOutcome::AlreadyWithdrawn => $this->done($io, \sprintf('The %s timetable was already withdrawn; it stays withdrawn and this operation is recorded.', $direction->value)),
            WithdrawalOutcome::NoVersion => $this->refuse($io, 'No gate version is set, so nothing is published.'),
        };
    }

    private function done(SymfonyStyle $io, string $message): int
    {
        $io->success($message);

        return Command::SUCCESS;
    }

    private function refuse(SymfonyStyle $io, string $message): int
    {
        $io->error($message.' Nothing was changed.');

        return Command::FAILURE;
    }

    /**
     * @param list<string> $names
     */
    private static function refuseOptions(InputInterface $input, array $names, string $action): void
    {
        foreach ($names as $name) {
            if (null !== $input->getOption($name)) {
                throw new TimetableGateInputInvalid(\sprintf('--%s cannot be used with "%s".', $name, $action));
            }
        }
    }

    private static function option(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return \is_string($value) ? $value : null;
    }
}
