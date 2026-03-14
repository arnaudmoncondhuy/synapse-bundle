<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Controller\Usage;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseSpendingLimitLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Historique des dépassements de plafond de dépense.
 */
#[Route('%synapse.admin_prefix%/usage/spending-alerts', name: 'synapse_admin_')]
class SpendingAlertsController extends AbstractController
{
    use AdminSecurityTrait;

    public function __construct(
        private readonly SynapseSpendingLimitLogRepository $logRepo,
        private readonly PermissionCheckerInterface $permissionChecker,
    ) {
    }

    #[Route('', name: 'spending_alerts', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $days = (int) $request->query->get('days', 30);
        $days = in_array($days, [7, 30, 90, 365], true) ? $days : 30;

        $scope = $request->query->get('scope', '');

        $from = new \DateTimeImmutable("-{$days} days");
        $to = new \DateTimeImmutable();

        $logs = $this->logRepo->findByPeriod($from, $to);

        if ('' !== $scope) {
            $logs = array_filter($logs, fn ($l) => $l->getScope() === $scope);
            $logs = array_values($logs);
        }

        $countByScope = $this->logRepo->countByScope($from, $to);

        return $this->render('@Synapse/admin/usage/spending_alerts.html.twig', [
            'logs' => $logs,
            'days' => $days,
            'scope_filter' => $scope,
            'count_by_scope' => $countByScope,
            'total' => array_sum($countByScope),
        ]);
    }
}
