<?php

declare(strict_types=1);

namespace App\Core\Presentation\Web\Controller\Tenant;

use App\Core\Domain\Tenant\TenantRole;
use App\Core\Infrastructure\Doctrine\Entity\TenantUser;
use App\Core\Infrastructure\Tenancy\TenantContext;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TenantAuditController extends AbstractController
{
    #[Route('/v/{slug}/einstellungen/audit', name: 'tenant_audit', methods: ['GET'])]
    public function __invoke(Request $request, TenantContext $context, Connection $connection): Response
    {
        $viewer = $this->getUser();
        if (!$viewer instanceof TenantUser || !in_array($viewer->getTenantRole(), [TenantRole::Owner, TenantRole::Administrator], true)) {
            throw $this->createAccessDeniedException();
        }
        $tenant = $context->get();
        $query = $connection->createQueryBuilder()
            ->select('a.action', 'a.subject_type', 'a.subject_public_id', 'a.context', 'a.occurred_at', 'COALESCE(tu.display_name, tu.email) AS tenant_user', 'COALESCE(pa.display_name, pa.email) AS platform_admin')
            ->from('audit_log', 'a')
            ->leftJoin('a', 'tenant_users', 'tu', 'tu.id = a.actor_user_id')
            ->leftJoin('a', 'platform_admins', 'pa', 'pa.id = a.actor_platform_admin_id')
            ->where('a.tenant_id = :tenant_id')
            ->setParameter('tenant_id', $tenant->getId())
            ->orderBy('a.occurred_at', 'DESC')
            ->setMaxResults(200);
        $action = trim($request->query->getString('action'));
        $user = trim($request->query->getString('user'));
        $from = trim($request->query->getString('from'));
        $to = trim($request->query->getString('to'));
        if ($action !== '') {
            $query->andWhere('a.action LIKE :action')->setParameter('action', '%'.$action.'%');
        }
        if ($user !== '') {
            $query->andWhere('tu.public_id = :user')->setParameter('user', $user);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1) {
            $query->andWhere('a.occurred_at >= :from')->setParameter('from', $from.' 00:00:00');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) === 1) {
            $query->andWhere('a.occurred_at < :to')->setParameter('to', (new \DateTimeImmutable($to, new \DateTimeZone('Europe/Zurich')))->add(new \DateInterval('P1D'))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        }

        return $this->render('tenant/audit.html.twig', [
            'tenant' => $tenant,
            'entries' => $query->executeQuery()->fetchAllAssociative(),
            'users' => $connection->fetchAllAssociative('SELECT public_id, display_name, email FROM tenant_users WHERE tenant_id = :tenant ORDER BY display_name', ['tenant' => $tenant->getId()]),
            'filters' => ['action' => $action, 'user' => $user, 'from' => $from, 'to' => $to],
        ]);
    }
}
