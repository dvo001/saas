<?php

declare(strict_types=1);

namespace App\Core\Presentation\Cli;

use App\Core\Infrastructure\Doctrine\Entity\Tenant;
use App\Core\Infrastructure\Doctrine\Entity\TenantUser;
use App\Core\Infrastructure\Security\AuditLogger;
use App\Core\Infrastructure\Security\OneTimeTokenStore;
use App\Core\Infrastructure\System\CronRunMonitor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

#[AsCommand(name: 'app:registrations:maintain', description: 'Remind and remove unconfirmed club registrations.')]
final class MaintainRegistrationsCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
        private readonly OneTimeTokenStore $tokens,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urls,
        private readonly AuditLogger $audit,
        private readonly CronRunMonitor $cronRuns,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jobName = 'registrations.maintain';
        $runId = $this->cronRuns->start($jobName);
        try {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT t.id AS tenant_id, t.public_id, t.created_at, t.registration_reminder_sent_at,
                   u.id AS user_id, u.email
            FROM tenants t
            JOIN tenant_users u ON u.tenant_id = t.id AND u.tenant_role = 'owner'
            WHERE t.status = 'pending_confirmation'
            ORDER BY t.id
            SQL);
        $reminded = 0;
        $deleted = 0;

        foreach ($rows as $row) {
            $createdAt = new \DateTimeImmutable((string) $row['created_at'], new \DateTimeZone('UTC'));
            $expiresAt = $createdAt->add(new \DateInterval('P7D'));
            if ($expiresAt <= $now) {
                $tenant = $this->entityManager->find(Tenant::class, (int) $row['tenant_id']);
                $user = $this->entityManager->find(TenantUser::class, (int) $row['user_id']);
                if ($tenant instanceof Tenant && $user instanceof TenantUser) {
                    $this->audit->log('registration.expired_deleted', 'tenant', (string) $row['public_id'], $tenant, $user);
                }
                $this->connection->delete('tenants', ['id' => $row['tenant_id']]);
                ++$deleted;

                continue;
            }
            if ($createdAt->add(new \DateInterval('P5D')) <= $now && $row['registration_reminder_sent_at'] === null) {
                $token = $this->tokens->issue((int) $row['tenant_id'], (int) $row['user_id'], 'registration_confirmation', $expiresAt);
                $url = $this->urls->generate('registration_confirm', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);
                $this->mailer->send((new Email())->to((string) $row['email'])->subject('Registrierung läuft in 2 Tagen ab')->text("Bestätigen Sie die Registrierung spätestens innerhalb der ursprünglichen 7-Tage-Frist:\n\n".$url));
                $this->connection->update('tenants', ['registration_reminder_sent_at' => $now->format('Y-m-d H:i:s')], ['id' => $row['tenant_id']]);
                ++$reminded;
            }
        }

        $output->writeln(sprintf('%d reminder(s) sent, %d expired registration(s) deleted.', $reminded, $deleted));
        $this->cronRuns->succeed($runId, $jobName, ['reminded' => $reminded, 'deleted' => $deleted]);

        return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $errorReference = Uuid::v7()->toRfc4122();
            $this->cronRuns->fail($runId, $jobName, $errorReference);
            $output->writeln('<error>Cronjob fehlgeschlagen. Fehlerreferenz: '.$errorReference.'</error>');

            throw $exception;
        }
    }
}
