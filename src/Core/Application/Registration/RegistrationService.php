<?php

declare(strict_types=1);

namespace App\Core\Application\Registration;

use App\Core\Domain\Tenant\TenantRole;
use App\Core\Domain\Tenant\TenantStatus;
use App\Core\Domain\Tenant\TrialModule;
use App\Core\Infrastructure\Doctrine\Entity\Tenant;
use App\Core\Infrastructure\Doctrine\Entity\TenantUser;
use App\Core\Infrastructure\Security\AuditLogger;
use App\Core\Infrastructure\Security\OneTimeTokenStore;
use App\Core\Infrastructure\Security\PasswordPolicy;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

final readonly class RegistrationService
{
    public const LEGAL_VERSION = '2026-08-21-v1';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private Connection $connection,
        private UserPasswordHasherInterface $passwordHasher,
        private PasswordPolicy $passwordPolicy,
        private OneTimeTokenStore $tokens,
        private AuditLogger $audit,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urls,
    ) {}

    /** @param array{club_name:string,slug:string,module:string,display_name:string,email:string,password:string} $data */
    public function register(array $data, string $ip): Tenant
    {
        $clubName = trim($data['club_name']);
        $slug = mb_strtolower(trim($data['slug']));
        $email = mb_strtolower(trim($data['email']));
        $violations = $this->validate($clubName, $slug, $email, $data['password']);
        if ($violations !== []) {
            throw new \DomainException(implode(' ', $violations));
        }

        $module = TrialModule::tryFrom($data['module']) ?? throw new \DomainException('Bitte ein gültiges Testmodul wählen.');
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $tenant = new Tenant(
            Uuid::v7()->toRfc4122(),
            $clubName,
            $slug,
            TenantStatus::PendingConfirmation,
            $module,
            self::LEGAL_VERSION,
            $now,
        );
        $user = new TenantUser(
            $tenant,
            Uuid::v7()->toRfc4122(),
            $email,
            $data['display_name'],
            TenantRole::Owner,
            '',
        );
        $user->changePassword($this->passwordHasher->hashPassword($user, $data['password']));

        $this->entityManager->wrapInTransaction(function () use ($tenant, $user, $now, $ip): void {
            $this->entityManager->persist($tenant);
            $this->entityManager->persist($user);
            $this->entityManager->flush();

            $token = $this->tokens->issue(
                $tenant->getId() ?? throw new \LogicException('Missing tenant id.'),
                $user->getId(),
                'registration_confirmation',
                $now->add(new \DateInterval('P7D')),
            );
            $confirmationUrl = $this->urls->generate('registration_confirm', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);
            $this->mailer->send((new Email())
                ->to($user->getEmail())
                ->subject('Registrierung bestätigen')
                ->text("Bestätigen Sie die Registrierung Ihres Vereins innert 7 Tagen:\n\n".$confirmationUrl));

            $this->audit->log('registration.created', 'tenant', $tenant->getPublicId(), $tenant, $user, ['legal_version' => self::LEGAL_VERSION], $ip);
        });

        return $tenant;
    }

    public function confirm(string $rawToken, string $ip): Tenant
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $this->connection->transactional(function () use ($rawToken, $now, $ip): Tenant {
            $row = $this->tokens->consume($rawToken, 'registration_confirmation', $now);
            if ($row === null) {
                throw new \DomainException('Der Bestätigungslink ist ungültig oder abgelaufen.');
            }

            $tenant = $this->entityManager->find(Tenant::class, (int) $row['tenant_id']);
            $user = $this->entityManager->find(TenantUser::class, (int) $row['user_id']);
            if (!$tenant instanceof Tenant || !$user instanceof TenantUser) {
                throw new \DomainException('Die Registrierung wurde nicht gefunden.');
            }

            $tenant->confirm($now);
            $user->confirmEmail();
            $this->entityManager->flush();
            $this->audit->log('registration.confirmed', 'tenant', $tenant->getPublicId(), $tenant, $user, [], $ip);

            return $tenant;
        });
    }

    public function resendConfirmation(string $email, string $slug): void
    {
        $row = $this->connection->fetchAssociative(<<<'SQL'
            SELECT t.id AS tenant_id, t.created_at, u.id AS user_id, u.email
            FROM tenants t
            JOIN tenant_users u ON u.tenant_id = t.id AND u.tenant_role = 'owner'
            WHERE t.slug = :slug AND u.email = :email AND t.status = 'pending_confirmation'
            LIMIT 1
            SQL, ['slug' => mb_strtolower(trim($slug)), 'email' => mb_strtolower(trim($email))]);
        if ($row === false) {
            return;
        }

        $createdAt = new \DateTimeImmutable((string) $row['created_at'], new \DateTimeZone('UTC'));
        $expiresAt = $createdAt->add(new \DateInterval('P7D'));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($expiresAt <= $now) {
            return;
        }
        $this->connection->executeStatement(
            "UPDATE tenant_auth_tokens SET consumed_at = :now WHERE tenant_id = :tenant AND token_type = 'registration_confirmation' AND consumed_at IS NULL",
            ['now' => $now->format('Y-m-d H:i:s'), 'tenant' => $row['tenant_id']],
        );
        $token = $this->tokens->issue((int) $row['tenant_id'], (int) $row['user_id'], 'registration_confirmation', $expiresAt);
        $url = $this->urls->generate('registration_confirm', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);
        $this->mailer->send((new Email())->to((string) $row['email'])->subject('Registrierung bestätigen')->text("Der neue Bestätigungslink ist bis zum Ende der ursprünglichen 7-Tage-Frist gültig:\n\n".$url));
    }

    /** @return list<string> */
    private function validate(string $clubName, string $slug, string $email, string $password): array
    {
        $violations = $this->passwordPolicy->violations($password);
        if ($clubName === '' || mb_strlen($clubName) > 180) {
            $violations[] = 'Bitte einen gültigen Vereinsnamen eingeben.';
        }
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1 || strlen($slug) > 80) {
            $violations[] = 'Der Vereins-Slug darf nur Kleinbuchstaben, Zahlen und Bindestriche enthalten.';
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $violations[] = 'Bitte eine gültige E-Mail-Adresse eingeben.';
        }
        if ($this->connection->fetchOne('SELECT 1 FROM tenants WHERE name = :name OR slug = :slug LIMIT 1', ['name' => $clubName, 'slug' => $slug]) !== false) {
            $violations[] = 'Vereinsname oder Slug ist bereits vergeben.';
        }
        if ($this->connection->fetchOne(<<<'SQL'
            SELECT 1 FROM tenant_users u
            JOIN tenants t ON t.id = u.tenant_id
            WHERE u.email = :email AND t.status = 'pending_confirmation'
            LIMIT 1
            SQL, ['email' => $email]) !== false) {
            $violations[] = 'Für diese E-Mail-Adresse besteht bereits eine offene Registrierung.';
        }

        return $violations;
    }
}
