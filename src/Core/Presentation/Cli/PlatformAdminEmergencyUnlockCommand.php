<?php

declare(strict_types=1);

namespace App\Core\Presentation\Cli;

use App\Core\Infrastructure\Doctrine\Repository\PlatformAdminRepository;
use App\Core\Infrastructure\Security\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:platform-admin:emergency-unlock', description: 'Server-side recovery when the sole platform administrator is locked.')]
final class PlatformAdminEmergencyUnlockCommand extends Command
{
    public function __construct(
        private readonly PlatformAdminRepository $admins,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $audit,
        private readonly string $emergencyRecoveryToken,
    ) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addOption('email', null, InputOption::VALUE_REQUIRED, 'Email of the sole platform administrator.');
        $this->addOption('token', null, InputOption::VALUE_REQUIRED, 'Server-side emergency recovery token.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $provided = (string) $input->getOption('token');
        if (strlen($this->emergencyRecoveryToken) < 32 || !hash_equals($this->emergencyRecoveryToken, $provided)) {
            $output->writeln('<error>Emergency recovery is not configured or the token is invalid.</error>');

            return Command::FAILURE;
        }
        if ($this->admins->countConfirmed() > 1) {
            $output->writeln('<error>A second platform administrator must perform the unlock in the admin UI.</error>');

            return Command::FAILURE;
        }
        $admin = $this->admins->findByEmail((string) $input->getOption('email'));
        if ($admin === null) {
            $output->writeln('<error>Platform administrator not found.</error>');

            return Command::FAILURE;
        }
        $admin->reactivate();
        $admin->unlock();
        $this->entityManager->flush();
        $this->audit->logPlatform('platform.admin.emergency_unlocked', 'platform_admin', $admin->getPublicId(), $admin, ['source' => 'server_cli']);
        $output->writeln('<info>Platform administrator unlocked. Rotate PLATFORM_EMERGENCY_RECOVERY_TOKEN now.</info>');

        return Command::SUCCESS;
    }
}
