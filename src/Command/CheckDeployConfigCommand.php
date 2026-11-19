<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Flight\TimetableFreshnessPolicy;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Run before every production deploy. Fails while a setting required for
 * public release is empty or still a placeholder, e.g. the contact form
 * settings (the ODPT developer guideline 3.1(3) requires a contact). Prints
 * variable names only, never their values.
 */
#[AsCommand(name: 'app:check-deploy-config', description: 'Fails if a setting required for public release is empty or a placeholder')]
final class CheckDeployConfigCommand extends Command
{
    /**
     * The mailer DSN schemes that deliver nothing, so a form submitted in
     * production would be lost.
     */
    private const NON_DELIVERING_MAILER_SCHEMES = ['null://', 'null:', 'native://'];

    public function __construct(
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
        #[Autowire(env: 'APP_SECRET')]
        private readonly string $appSecret,
        #[Autowire(env: 'CONTACT_RECIPIENT')]
        private readonly string $contactRecipient,
        #[Autowire(env: 'CONTACT_FROM')]
        private readonly string $contactFrom,
        #[Autowire(env: 'MAILER_DSN')]
        private readonly string $mailerDsn,
        private readonly TimetableFreshnessPolicy $timetableFreshnessPolicy,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        // Other environments load committed values (e.g. APP_SECRET in .env.dev),
        // which would let the check pass without the production settings.
        if ('prod' !== $this->environment) {
            $errorOutput->writeln(\sprintf('<error>Run with APP_ENV=prod (current: %s).</error>', $this->environment));

            return Command::FAILURE;
        }

        $missing = [];
        if ('' === trim($this->appSecret)) {
            $missing[] = 'APP_SECRET';
        }
        if ('' === trim($this->contactRecipient)) {
            $missing[] = 'CONTACT_RECIPIENT';
        }
        if ('' === trim($this->contactFrom)) {
            $missing[] = 'CONTACT_FROM';
        }
        if ('' === trim($this->mailerDsn) || $this->isNonDeliveringMailer($this->mailerDsn)) {
            $missing[] = 'MAILER_DSN';
        }

        // Checked at run time as well; this only catches it earlier (§7 step 7).
        if (!$this->timetableFreshnessPolicy->isValid()) {
            $missing[] = 'TIMETABLE_WARN_AFTER_SECONDS / TIMETABLE_SUSPEND_AFTER_SECONDS';
        }

        if ([] !== $missing) {
            $errorOutput->writeln(\sprintf('<error>Unset or invalid settings (empty, placeholder or out of range): %s</error>', implode(', ', $missing)));

            return Command::FAILURE;
        }

        $output->writeln('Deploy settings OK.');

        return Command::SUCCESS;
    }

    private function isNonDeliveringMailer(string $dsn): bool
    {
        $dsn = strtolower(trim($dsn));
        foreach (self::NON_DELIVERING_MAILER_SCHEMES as $scheme) {
            if (str_starts_with($dsn, $scheme)) {
                return true;
            }
        }

        return false;
    }
}
