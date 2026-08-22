<?php

declare(strict_types=1);

namespace App\Core\Presentation\Cli;

use App\Core\Application\Cron\CronJobRunner;
use App\Core\Infrastructure\System\CronRunMonitor;
use App\Core\Infrastructure\Mail\SystemMailer;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;

#[AsCommand(name: 'app:cron:run', description: 'Führt fällige Hintergrundjobs überwacht und shared-hosting-tauglich aus.')]
final class CronRunnerCommand extends Command
{
    private const JOBS = ['trials', 'billing', 'maintenance', 'exports', 'retention'];
    public function __construct(private readonly CronJobRunner $runner, private readonly CronRunMonitor $monitor, private readonly SystemMailer $mailer, private readonly Connection $connection) { parent::__construct(); }
    protected function configure(): void { $this->addOption('job', null, InputOption::VALUE_REQUIRED, 'Nur einen Job ausführen')->addOption('preview', null, InputOption::VALUE_NONE, 'Löschjob nur als Vorschau ausführen'); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selected = $input->getOption('job');
        if (is_string($selected) && !in_array($selected, self::JOBS, true)) { $output->writeln('<error>Unbekannter Job.</error>'); return Command::INVALID; }
        $jobs = is_string($selected) ? [$selected] : self::JOBS; $failed = false;
        foreach ($jobs as $job) {
            $name = 'cron.'.$job; $runId = $this->monitor->start($name);
            try {
                $result = match ($job) { 'trials' => $this->runner->trials(), 'billing' => $this->runner->billing(), 'maintenance' => $this->runner->maintenance(), 'exports' => $this->runner->exports(), 'retention' => $this->runner->retention(preview: (bool) $input->getOption('preview')) };
                $this->monitor->succeed($runId, $name, $result); $output->writeln($name.': '.json_encode($result, JSON_THROW_ON_ERROR));
            } catch (\Throwable) {
                $reference = Uuid::v7()->toRfc4122(); $this->monitor->fail($runId, $name, $reference); $output->writeln('<error>'.$name.' fehlgeschlagen. Fehlerreferenz: '.$reference.'</error>'); $failed = true;
                foreach ($this->connection->fetchFirstColumn('SELECT email FROM platform_admins WHERE active = 1 AND deleted_at IS NULL') as $email) {
                    try { $this->mailer->send((string) $email, 'Cronjob fehlgeschlagen: '.$name, 'Fehlerreferenz: '.$reference, 'cron_failed'); } catch (\Throwable) { /* Der ursprüngliche Fehler bleibt maßgeblich. */ }
                }
            }
        }
        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
