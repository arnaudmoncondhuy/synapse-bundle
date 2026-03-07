<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Controller\Usage;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\DatabaseConfigProvider;
use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\SpendingLimitPeriod;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\SpendingLimitScope;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseSpendingLimit;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseConfigRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseLlmCallRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseAgentRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelPresetRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseSpendingLimitRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Quotas / Limites de dépense — Administration Synapse.
 *
 * Toggle global des limites, CRUD des plafonds (user / preset), stats par user et par preset.
 */
#[Route('%synapse.admin_prefix%/usage/quotas', name: 'synapse_admin_')]
class QuotasController extends AbstractController
{
    use AdminSecurityTrait;

    public function __construct(
        private SynapseConfigRepository $configRepo,
        private SynapseSpendingLimitRepository $spendingLimitRepo,
        private SynapseLlmCallRepository $tokenUsageRepo,
        private SynapseModelPresetRepository $presetRepo,
        private SynapseAgentRepository $agentRepo,
        private EntityManagerInterface $em,
        private DatabaseConfigProvider $configProvider,
        private PermissionCheckerInterface $permissionChecker,
        private ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ) {}

    #[Route('', name: 'quotas', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $config = $this->configRepo->getGlobalConfig();

        if ($request->isMethod('POST')) {
            $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_admin_quotas');

            $action = $request->request->get('action', '');

            if ('toggle_limits' === $action) {
                $config->setSpendingLimitsEnabled($request->request->getBoolean('limits_enabled'));
                $this->em->flush();
                $this->configProvider->clearCache();
                $this->addFlash('success', $config->isSpendingLimitsEnabled()
                    ? 'Les limites de coût sont maintenant actives.'
                    : 'Les limites de coût sont désactivées. Le comptage des tokens reste actif pour les stats.');

                return $this->redirectToRoute('synapse_admin_quotas');
            }

            if ('add_limit' === $action) {
                $scope = $request->request->get('scope');
                $scopeId = trim((string) $request->request->get('scope_id', ''));
                $amount = trim((string) $request->request->get('amount', ''));
                $currency = trim((string) $request->request->get('currency', 'EUR'));
                $period = $request->request->get('period');
                $name = trim((string) $request->request->get('name', ''));

                if ($scope === SpendingLimitScope::USER->value && '' !== $scopeId) {
                    $limit = new SynapseSpendingLimit();
                    $limit->setScope(SpendingLimitScope::USER);
                    $limit->setScopeId($scopeId);
                    $limit->setAmount('' !== $amount ? $amount : '0');
                    $limit->setCurrency('' !== $currency ? $currency : 'EUR');
                    $limit->setPeriod($this->parsePeriod(is_string($period) ? $period : null));
                    $limit->setName('' !== $name ? $name : null);
                    $this->em->persist($limit);
                    $this->em->flush();
                    $this->addFlash('success', 'Plafond utilisateur ajouté.');
                } elseif ($scope === SpendingLimitScope::PRESET->value && '' !== $scopeId) {
                    $limit = new SynapseSpendingLimit();
                    $limit->setScope(SpendingLimitScope::PRESET);
                    $limit->setScopeId($scopeId);
                    $limit->setAmount('' !== $amount ? $amount : '0');
                    $limit->setCurrency('' !== $currency ? $currency : 'EUR');
                    $limit->setPeriod($this->parsePeriod(is_string($period) ? $period : null));
                    $limit->setName('' !== $name ? $name : null);
                    $this->em->persist($limit);
                    $this->em->flush();
                    $this->addFlash('success', 'Plafond preset ajouté.');
                } elseif ($scope === SpendingLimitScope::AGENT->value && '' !== $scopeId) {
                    $limit = new SynapseSpendingLimit();
                    $limit->setScope(SpendingLimitScope::AGENT);
                    $limit->setScopeId($scopeId);
                    $limit->setAmount('' !== $amount ? $amount : '0');
                    $limit->setCurrency('' !== $currency ? $currency : 'EUR');
                    $limit->setPeriod($this->parsePeriod(is_string($period) ? $period : null));
                    $limit->setName('' !== $name ? $name : null);
                    $this->em->persist($limit);
                    $this->em->flush();
                    $this->addFlash('success', 'Plafond agent ajouté.');
                } else {
                    $this->addFlash('error', 'Veuillez renseigner le périmètre (utilisateur, preset ou agent) et l\'identifiant.');
                }

                return $this->redirectToRoute('synapse_admin_quotas');
            }

            if ('delete_limit' === $action) {
                $id = (int) $request->request->get('limit_id', 0);
                $limit = $this->spendingLimitRepo->find($id);
                if (null !== $limit) {
                    $this->em->remove($limit);
                    $this->em->flush();
                    $this->addFlash('success', 'Limite supprimée.');
                }

                return $this->redirectToRoute('synapse_admin_quotas');
            }
        }

        $period = (int) $request->query->get('period', 30);
        $period = in_array($period, [7, 30, 90], true) ? $period : 30;
        $start = new \DateTimeImmutable("-{$period} days");
        $end = new \DateTimeImmutable();

        $limits = $this->spendingLimitRepo->findBy([], ['scope' => 'ASC', 'scopeId' => 'ASC']);
        $presets = $this->presetRepo->findBy([], ['name' => 'ASC']);
        $agents = $this->agentRepo->findAllOrdered();
        $usageByUser = $this->tokenUsageRepo->getUsageByUser($start, $end);
        $usageByPreset = $this->tokenUsageRepo->getUsageByPreset($start, $end);
        $usageByAgent = $this->tokenUsageRepo->getUsageByAgent($start, $end);

        $periodLabel = match ($period) {
            7 => '7 derniers jours',
            90 => '3 derniers mois',
            default => '30 derniers jours',
        };

        $presetsById = [];
        foreach ($presets as $p) {
            $presetsById[$p->getId()] = $p;
        }
        $agentsById = [];
        foreach ($agents as $m) {
            $agentsById[$m->getId()] = $m;
        }

        return $this->render('@Synapse/admin/usage/quotas.html.twig', [
            'config' => $config,
            'limits' => $limits,
            'presets' => $presets,
            'agents' => $agents,
            'presets_by_id' => $presetsById,
            'agents_by_id' => $agentsById,
            'usage_by_user' => $usageByUser,
            'usage_by_preset' => $usageByPreset,
            'usage_by_agent' => $usageByAgent,
            'period' => $period,
            'period_label' => $periodLabel,
            'periods' => [
                SpendingLimitPeriod::SLIDING_DAY->value => 'Glissante (4h)',
                SpendingLimitPeriod::SLIDING_MONTH->value => 'Glissante 30j',
                SpendingLimitPeriod::CALENDAR_DAY->value => 'Jour calendaire',
                SpendingLimitPeriod::CALENDAR_MONTH->value => 'Mois calendaire',
            ],
        ]);
    }

    private function parsePeriod(?string $period): SpendingLimitPeriod
    {
        if (null !== $period && in_array($period, ['sliding_day', 'sliding_month', 'calendar_day', 'calendar_month'], true)) {
            return SpendingLimitPeriod::from($period);
        }

        return SpendingLimitPeriod::CALENDAR_MONTH;
    }
}
