<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\AdminV2\Controller\Usage;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Core\DatabaseConfigProvider;
use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\SpendingLimitPeriod;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\SpendingLimitScope;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseSpendingLimit;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseConfigRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseMissionRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapsePresetRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseSpendingLimitRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseTokenUsageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Quotas / Limites de dépense — Admin V2
 *
 * Toggle global des limites, CRUD des plafonds (user / preset), stats par user et par preset.
 */
#[Route('/synapse/admin-v2/usage/quotas', name: 'synapse_v2_admin_quotas')]
class QuotasController extends AbstractController
{
    use AdminSecurityTrait;

    public function __construct(
        private SynapseConfigRepository $configRepo,
        private SynapseSpendingLimitRepository $spendingLimitRepo,
        private SynapseTokenUsageRepository $tokenUsageRepo,
        private SynapsePresetRepository $presetRepo,
        private SynapseMissionRepository $missionRepo,
        private EntityManagerInterface $em,
        private DatabaseConfigProvider $configProvider,
        private PermissionCheckerInterface $permissionChecker,
        private ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ) {}

    #[Route('', name: '', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $config = $this->configRepo->getGlobalConfig();

        if ($request->isMethod('POST')) {
            $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_v2_admin_quotas');

            $action = $request->request->get('action', '');

            if ($action === 'toggle_limits') {
                $config->setSpendingLimitsEnabled($request->request->getBoolean('limits_enabled'));
                $this->em->flush();
                $this->configProvider->clearCache();
                $this->addFlash('success', $config->isSpendingLimitsEnabled()
                    ? 'Les limites de coût sont maintenant actives.'
                    : 'Les limites de coût sont désactivées. Le comptage des tokens reste actif pour les stats.');
                return $this->redirectToRoute('synapse_v2_admin_quotas');
            }

            if ($action === 'add_limit') {
                $scope = $request->request->get('scope');
                $scopeId = trim((string) $request->request->get('scope_id', ''));
                $amount = trim((string) $request->request->get('amount', ''));
                $currency = trim((string) $request->request->get('currency', 'EUR'));
                $period = $request->request->get('period');
                $name = trim((string) $request->request->get('name', ''));

                if ($scope === SpendingLimitScope::USER->value && $scopeId !== '') {
                    $limit = new SynapseSpendingLimit();
                    $limit->setScope(SpendingLimitScope::USER);
                    $limit->setScopeId($scopeId);
                    $limit->setAmount($amount !== '' ? $amount : '0');
                    $limit->setCurrency($currency !== '' ? $currency : 'EUR');
                    $limit->setPeriod($this->parsePeriod($period));
                    $limit->setName($name !== '' ? $name : null);
                    $this->em->persist($limit);
                    $this->em->flush();
                    $this->addFlash('success', 'Plafond utilisateur ajouté.');
                } elseif ($scope === SpendingLimitScope::PRESET->value && $scopeId !== '') {
                    $limit = new SynapseSpendingLimit();
                    $limit->setScope(SpendingLimitScope::PRESET);
                    $limit->setScopeId($scopeId);
                    $limit->setAmount($amount !== '' ? $amount : '0');
                    $limit->setCurrency($currency !== '' ? $currency : 'EUR');
                    $limit->setPeriod($this->parsePeriod($period));
                    $limit->setName($name !== '' ? $name : null);
                    $this->em->persist($limit);
                    $this->em->flush();
                    $this->addFlash('success', 'Plafond preset ajouté.');
                } elseif ($scope === SpendingLimitScope::MISSION->value && $scopeId !== '') {
                    $limit = new SynapseSpendingLimit();
                    $limit->setScope(SpendingLimitScope::MISSION);
                    $limit->setScopeId($scopeId);
                    $limit->setAmount($amount !== '' ? $amount : '0');
                    $limit->setCurrency($currency !== '' ? $currency : 'EUR');
                    $limit->setPeriod($this->parsePeriod($period));
                    $limit->setName($name !== '' ? $name : null);
                    $this->em->persist($limit);
                    $this->em->flush();
                    $this->addFlash('success', 'Plafond mission ajouté.');
                } else {
                    $this->addFlash('error', 'Veuillez renseigner le périmètre (utilisateur, preset ou mission) et l\'identifiant.');
                }
                return $this->redirectToRoute('synapse_v2_admin_quotas');
            }

            if ($action === 'delete_limit') {
                $id = (int) $request->request->get('limit_id', 0);
                $limit = $this->spendingLimitRepo->find($id);
                if ($limit !== null) {
                    $this->em->remove($limit);
                    $this->em->flush();
                    $this->addFlash('success', 'Limite supprimée.');
                }
                return $this->redirectToRoute('synapse_v2_admin_quotas');
            }
        }

        $period = (int) $request->query->get('period', 30);
        $period = in_array($period, [7, 30, 90], true) ? $period : 30;
        $start = new \DateTimeImmutable("-{$period} days");
        $end = new \DateTimeImmutable();

        $limits = $this->spendingLimitRepo->findBy([], ['scope' => 'ASC', 'scopeId' => 'ASC']);
        $presets = $this->presetRepo->findBy([], ['name' => 'ASC']);
        $missions = $this->missionRepo->findAllOrdered();
        $usageByUser = $this->tokenUsageRepo->getUsageByUser($start, $end);
        $usageByPreset = $this->tokenUsageRepo->getUsageByPreset($start, $end);
        $usageByMission = $this->tokenUsageRepo->getUsageByMission($start, $end);

        $periodLabel = match ($period) {
            7 => '7 derniers jours',
            90 => '3 derniers mois',
            default => '30 derniers jours',
        };

        $presetsById = [];
        foreach ($presets as $p) {
            $presetsById[$p->getId()] = $p;
        }
        $missionsById = [];
        foreach ($missions as $m) {
            $missionsById[$m->getId()] = $m;
        }

        return $this->render('@Synapse/admin_v2/usage/quotas.html.twig', [
            'config' => $config,
            'limits' => $limits,
            'presets' => $presets,
            'missions' => $missions,
            'presets_by_id' => $presetsById,
            'missions_by_id' => $missionsById,
            'usage_by_user' => $usageByUser,
            'usage_by_preset' => $usageByPreset,
            'usage_by_mission' => $usageByMission,
            'period' => $period,
            'period_label' => $periodLabel,
            'periods' => [
                SpendingLimitPeriod::SLIDING_DAY->value => 'Glissante 24h',
                SpendingLimitPeriod::SLIDING_MONTH->value => 'Glissante 30j',
                SpendingLimitPeriod::CALENDAR_DAY->value => 'Jour calendaire',
                SpendingLimitPeriod::CALENDAR_MONTH->value => 'Mois calendaire',
            ],
        ]);
    }

    private function parsePeriod(?string $period): SpendingLimitPeriod
    {
        if ($period !== null && in_array($period, ['sliding_day', 'sliding_month', 'calendar_day', 'calendar_month'], true)) {
            return SpendingLimitPeriod::from($period);
        }
        return SpendingLimitPeriod::CALENDAR_MONTH;
    }
}
