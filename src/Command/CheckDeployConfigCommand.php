<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Run before every production deploy. Fails while a setting required for
 * public release is empty, e.g. the contact address that the ODPT developer
 * guideline 3.1(3) requires. Prints variable names only, never their values.
 */
#[AsCommand(name: 'app:check-deploy-config', description: 'Fails if a setting required for public release is empty')]
final class CheckDeployConfigCommand extends Command
{
    public function __construct(
        #[Autowire(env: 'APP_SECRET')]
        private readonly string $appSecret,
        #[Autowire(env: 'CONTACT_ADDRESS')]
        private readonly string $contactAddress,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $missing = array_keys(array_filter(
            ['APP_SECRET' => $this->appSecret, 'CONTACT_ADDRESS' => $this->contactAddress],
            static fn (string $value): bool => '' === trim($value),
        ));

        if ([] !== $missing) {
            $output->writeln(\sprintf('<error>Empty settings: %s</error>', implode(', ', $missing)));

            return Command::FAILURE;
        }

        $output->writeln('Deploy settings OK.');

        return Command::SUCCESS;
    }
}
