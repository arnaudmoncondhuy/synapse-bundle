<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Admin\Controller\Intelligence;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\SpendingLimitPeriod;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseMission;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseSpendingLimit;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\SpendingLimitScope;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseMissionRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapsePresetRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseSpendingLimitRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseToneRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Gestion des missions d'agents — Administration Synapse
 *
 * Une mission combine un prompt système dédié avec un preset LLM et un ton optionnels.
 * Les missions builtin fournies par le bundle ne peuvent pas être supprimées.
 */
#[Route('/synapse/admin/intelligence/missions', name: 'synapse_admin_')]
class MissionController extends AbstractController
{
    use AdminSecurityTrait;

    public function __construct(
        private SynapseMissionRepository $missionRepo,
        private SynapsePresetRepository $presetRepo,
        private SynapseToneRepository $toneRepo,
        private SynapseSpendingLimitRepository $spendingLimitRepo,
        private EntityManagerInterface $em,
        private PermissionCheckerInterface $permissionChecker,
        private ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ) {}

    // ─── Index ─────────────────────────────────────────────────────────────────

    #[Route('', name: 'missions', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $missions = $this->missionRepo->findAllOrdered();

        return $this->render('@Synapse/admin/intelligence/missions.html.twig', [
            'missions' => $missions,
        ]);
    }

    // ─── Nouveau ───────────────────────────────────────────────────────────────

    #[Route('/nouveau', name: 'missions_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $mission = new SynapseMission();
        $mission->setIsBuiltin(false);

        if ($request->isMethod('POST')) {
            $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_mission_edit');
            $this->applyFormData($mission, $request->request->all());
            $this->em->persist($mission);
            $this->em->flush();

            $this->addFlash('success', sprintf('Mission "%s" créée avec succès.', $mission->getName()));
            return $this->redirectToRoute('synapse_admin_missions');
        }

        $presets = $this->presetRepo->findAll();
        $tones = $this->toneRepo->findAllOrdered();

        return $this->render('@Synapse/admin/intelligence/mission_edit.html.twig', [
            'mission'  => $mission,
            'is_new'   => true,
            'presets'  => $presets,
            'tones'    => $tones,
        ]);
    }

    // ─── Édition ───────────────────────────────────────────────────────────────

    #[Route('/{id}/editer', name: 'missions_edit', methods: ['GET', 'POST'])]
    public function edit(SynapseMission $mission, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        if ($request->isMethod('POST')) {
            $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_mission_edit');
            $this->applyFormData($mission, $request->request->all());
            $this->em->flush();

            $this->addFlash('success', sprintf('Mission "%s" mise à jour.', $mission->getName()));
            return $this->redirectToRoute('synapse_admin_missions');
        }

        $presets = $this->presetRepo->findAll();
        $tones = $this->toneRepo->findAllOrdered();
        $missionLimits = $this->spendingLimitRepo->findForMission($mission->getId());
        $spendingLimit = $missionLimits[0] ?? null;

        return $this->render('@Synapse/admin/intelligence/mission_edit.html.twig', [
            'mission'        => $mission,
            'is_new'         => false,
            'presets'        => $presets,
            'tones'          => $tones,
            'spending_limit' => $spendingLimit,
            'periods'        => [
                SpendingLimitPeriod::SLIDING_DAY->value => 'Glissante (4h)',
                SpendingLimitPeriod::SLIDING_MONTH->value => 'Glissante 30j',
                SpendingLimitPeriod::CALENDAR_DAY->value => 'Jour calendaire',
                SpendingLimitPeriod::CALENDAR_MONTH->value => 'Mois calendaire',
            ],
        ]);
    }

    // ─── Limite de dépense (mission) ───────────────────────────────────────────

    #[Route('/{id}/limite', name: 'missions_limit', methods: ['POST'])]
    public function saveLimit(SynapseMission $mission, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_mission_limit_' . $mission->getId());

        $action = $request->request->get('action', 'save');
        $missionLimits = $this->spendingLimitRepo->findForMission($mission->getId());
        $limit = $missionLimits[0] ?? null;

        if ($action === 'delete' && $limit !== null) {
            $this->em->remove($limit);
            $this->em->flush();
            $this->addFlash('success', 'Limite de dépense supprimée pour cette mission.');
            return $this->redirectToRoute('synapse_admin_missions_edit', ['id' => $mission->getId()]);
        }

        $amount = trim((string) $request->request->get('amount', ''));
        $currency = trim((string) $request->request->get('currency', 'EUR'));
        $period = $request->request->get('period');
        $name = trim((string) $request->request->get('name', ''));

        if ($amount === '') {
            $this->addFlash('error', 'Le montant est requis.');
            return $this->redirectToRoute('synapse_admin_missions_edit', ['id' => $mission->getId()]);
        }

        $isNew = ($limit === null);
        if ($limit === null) {
            $limit = new SynapseSpendingLimit();
            $limit->setScope(SpendingLimitScope::MISSION);
            $limit->setScopeId((string) $mission->getId());
            $this->em->persist($limit);
        }

        $limit->setAmount($amount);
        $limit->setCurrency($currency !== '' ? $currency : 'EUR');
        $limit->setPeriod($this->parsePeriod($period));
        $limit->setName($name !== '' ? $name : null);
        $this->em->flush();

        $this->addFlash('success', $isNew ? 'Limite de dépense ajoutée pour cette mission.' : 'Limite de dépense mise à jour.');
        return $this->redirectToRoute('synapse_admin_missions_edit', ['id' => $mission->getId()]);
    }

    private function parsePeriod(?string $period): SpendingLimitPeriod
    {
        if ($period !== null && in_array($period, ['sliding_day', 'sliding_month', 'calendar_day', 'calendar_month'], true)) {
            return SpendingLimitPeriod::from($period);
        }
        return SpendingLimitPeriod::CALENDAR_MONTH;
    }

    // ─── Toggle actif/inactif ──────────────────────────────────────────────────

    #[Route('/{id}/toggle', name: 'missions_toggle', methods: ['POST'])]
    public function toggle(SynapseMission $mission, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_mission_toggle_' . $mission->getId());

        $mission->setIsActive(!$mission->isActive());
        $this->em->flush();

        $state = $mission->isActive() ? 'activée' : 'désactivée';
        $this->addFlash('success', sprintf('Mission "%s" %s.', $mission->getName(), $state));

        return $this->redirectToRoute('synapse_admin_missions');
    }

    // ─── Suppression ───────────────────────────────────────────────────────────

    #[Route('/{id}/supprimer', name: 'missions_delete', methods: ['POST'])]
    public function delete(SynapseMission $mission, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_mission_delete_' . $mission->getId());

        if ($mission->isBuiltin()) {
            $this->addFlash('error', 'Les missions intégrées au bundle ne peuvent pas être supprimées.');
            return $this->redirectToRoute('synapse_admin_missions');
        }

        $name = $mission->getName();
        $this->em->remove($mission);
        $this->em->flush();

        $this->addFlash('success', sprintf('Mission "%s" supprimée.', $name));
        return $this->redirectToRoute('synapse_admin_missions');
    }

    // ─── Helpers ───────────────────────────────────────────────────────────────

    private function applyFormData(SynapseMission $mission, array $data): void
    {
        if (isset($data['key']) && !$mission->isBuiltin()) {
            $mission->setKey(trim((string) $data['key']));
        }

        if (isset($data['emoji'])) {
            $mission->setEmoji(trim((string) $data['emoji']));
        }

        if (isset($data['name'])) {
            $mission->setName(trim((string) $data['name']));
        }

        if (isset($data['description'])) {
            $mission->setDescription(trim((string) $data['description']));
        }

        if (isset($data['system_prompt'])) {
            $mission->setSystemPrompt(trim((string) $data['system_prompt']));
        }

        // Set preset if provided, otherwise null
        if (isset($data['preset_id']) && !empty($data['preset_id'])) {
            $preset = $this->presetRepo->find((int) $data['preset_id']);
            $mission->setPreset($preset);
        } else {
            $mission->setPreset(null);
        }

        // Set tone if provided, otherwise null
        if (isset($data['tone_id']) && !empty($data['tone_id'])) {
            $tone = $this->toneRepo->find((int) $data['tone_id']);
            $mission->setTone($tone);
        } else {
            $mission->setTone(null);
        }

        $mission->setIsActive(isset($data['is_active']));

        if (isset($data['sort_order'])) {
            $mission->setSortOrder((int) $data['sort_order']);
        }
    }
}
