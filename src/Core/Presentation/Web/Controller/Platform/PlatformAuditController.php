<?php

declare(strict_types=1);

namespace App\Core\Presentation\Web\Controller\Platform;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PlatformAuditController extends AbstractController
{
    #[Route('/platform/audit', name: 'platform_audit', methods: ['GET'])]
    public function __invoke(Request $request, Connection $connection): Response
    {
        $query = $connection->createQueryBuilder()
            ->select('a.id', 'a.action', 'a.subject_type', 'a.subject_public_id', 'a.context', 'a.occurred_at', 't.name AS tenant_name', 'COALESCE(pa.display_name, pa.email) AS platform_admin', 'COALESCE(tu.display_name, tu.email) AS tenant_user')
            ->from('audit_log', 'a')
            ->leftJoin('a', 'tenants', 't', 't.id = a.tenant_id')
            ->leftJoin('a', 'platform_admins', 'pa', 'pa.id = a.actor_platform_admin_id')
            ->leftJoin('a', 'tenant_users', 'tu', 'tu.id = a.actor_user_id')
            ->orderBy('a.occurred_at', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults(200);

        $action = trim($request->query->getString('action'));
        $tenant = trim($request->query->getString('tenant'));
        $from = trim($request->query->getString('from'));
        $to = trim($request->query->getString('to'));
        if ($action !== '') {
            $query->andWhere('a.action LIKE :action')->setParameter('action', '%'.$action.'%');
        }
        if ($tenant !== '') {
            $query->andWhere('t.public_id = :tenant')->setParameter('tenant', $tenant);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1) {
            $query->andWhere('a.occurred_at >= :from')->setParameter('from', $from.' 00:00:00');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) === 1) {
            $query->andWhere('a.occurred_at < :to')->setParameter('to', (new \DateTimeImmutable($to, new \DateTimeZone('Europe/Zurich')))->add(new \DateInterval('P1D'))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        }

        return $this->render('platform/admin/audit.html.twig', [
            'entries' => $query->executeQuery()->fetchAllAssociative(),
            'tenants' => $connection->fetchAllAssociative('SELECT public_id, name FROM tenants ORDER BY name'),
            'filters' => ['action' => $action, 'tenant' => $tenant, 'from' => $from, 'to' => $to],
        ]);
    }
}
