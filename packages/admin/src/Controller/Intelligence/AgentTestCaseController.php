<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Controller\Intelligence;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Governance\AgentTestRunner;
use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgentTestCase;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseAgentRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseAgentTestCaseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * CRUD des cas de test reproductibles attachés aux agents — Garde-fou #4.
 *
 * Les test cases sont stockés via {@see SynapseAgentTestCase} et exécutés
 * par {@see AgentTestRunner}. Ce contrôleur fournit la gestion CRUD
 * (create / edit / delete / toggle / run) depuis l'admin.
 */
#[Route('%synapse.admin_prefix%/intelligence/agents/{agentId}/test-cases', name: 'synapse_admin_')]
class AgentTestCaseController extends AbstractController
{
    use AdminSecurityTrait;

    public function __construct(
        private readonly SynapseAgentRepository $agentRepo,
        private readonly SynapseAgentTestCaseRepository $testCaseRepo,
        private readonly AgentTestRunner $testRunner,
        private readonly EntityManagerInterface $em,
        private readonly PermissionCheckerInterface $permissionChecker,
        private readonly TranslatorInterface $translator,
        private readonly ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ) {
    }

    // ─── Index ─────────────────────────────────────────────────────────────────

    #[Route('', name: 'agent_test_cases', methods: ['GET'])]
    public function index(int $agentId): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $agent = $this->findAgentOrFail($agentId);

        $testCases = $this->testCaseRepo->findAllForAgent($agent);

        return $this->render('@Synapse/admin/intelligence/agent_test_cases.html.twig', [
            'agent' => $agent,
            'test_cases' => $testCases,
        ]);
    }

    // ─── Nouveau ───────────────────────────────────────────────────────────────

    #[Route('/nouveau', name: 'agent_test_cases_new', methods: ['GET', 'POST'])]
    public function new(int $agentId, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $agent = $this->findAgentOrFail($agentId);

        $testCase = new SynapseAgentTestCase();
        $testCase->setAgent($agent);
        $errors = [];

        if ($request->isMethod('POST')) {
            $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_test_case_edit');
            $errors = $this->applyFormData($testCase, $request->request->all());

            if ([] === $errors) {
                $this->em->persist($testCase);
                $this->em->flush();

                $this->addFlash('success', $this->translator->trans('synapse.admin.test_case.flash.created', ['name' => $testCase->getName()], 'synapse_admin'));

                return $this->redirectToRoute('synapse_admin_agent_test_cases', ['agentId' => $agent->getId()]);
            }
        }

        return $this->render('@Synapse/admin/intelligence/agent_test_case_edit.html.twig', [
            'agent' => $agent,
            'test_case' => $testCase,
            'is_new' => true,
            'errors' => $errors,
        ]);
    }

    // ─── Édition ───────────────────────────────────────────────────────────────

    #[Route('/{id}/editer', name: 'agent_test_cases_edit', methods: ['GET', 'POST'])]
    public function edit(int $agentId, SynapseAgentTestCase $testCase, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $agent = $this->findAgentOrFail($agentId);

        $errors = [];

        if ($request->isMethod('POST')) {
            $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_test_case_edit');
            $errors = $this->applyFormData($testCase, $request->request->all());

            if ([] === $errors) {
                $this->em->flush();

                $this->addFlash('success', $this->translator->trans('synapse.admin.test_case.flash.updated', ['name' => $testCase->getName()], 'synapse_admin'));

                return $this->redirectToRoute('synapse_admin_agent_test_cases', ['agentId' => $agent->getId()]);
            }
        }

        return $this->render('@Synapse/admin/intelligence/agent_test_case_edit.html.twig', [
            'agent' => $agent,
            'test_case' => $testCase,
            'is_new' => false,
            'errors' => $errors,
        ]);
    }

    // ─── Toggle actif/inactif ──────────────────────────────────────────────────

    #[Route('/{id}/toggle', name: 'agent_test_cases_toggle', methods: ['POST'])]
    public function toggle(int $agentId, SynapseAgentTestCase $testCase, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->findAgentOrFail($agentId);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_test_case_toggle_'.$testCase->getId());

        $testCase->setIsActive(!$testCase->isActive());
        $this->em->flush();

        $stateKey = $testCase->isActive() ? 'synapse.admin.test_case.flash.enabled' : 'synapse.admin.test_case.flash.disabled';
        $this->addFlash('success', $this->translator->trans($stateKey, ['name' => $testCase->getName()], 'synapse_admin'));

        return $this->redirectToRoute('synapse_admin_agent_test_cases', ['agentId' => $agentId]);
    }

    // ─── Suppression ───────────────────────────────────────────────────────────

    #[Route('/{id}/supprimer', name: 'agent_test_cases_delete', methods: ['POST'])]
    public function delete(int $agentId, SynapseAgentTestCase $testCase, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->findAgentOrFail($agentId);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_test_case_delete_'.$testCase->getId());

        $name = $testCase->getName();
        $this->em->remove($testCase);
        $this->em->flush();

        $this->addFlash('success', $this->translator->trans('synapse.admin.test_case.flash.deleted', ['name' => $name], 'synapse_admin'));

        return $this->redirectToRoute('synapse_admin_agent_test_cases', ['agentId' => $agentId]);
    }

    // ─── Exécution d'un test case ──────────────────────────────────────────────

    #[Route('/{id}/executer', name: 'agent_test_cases_run', methods: ['POST'])]
    public function run(int $agentId, SynapseAgentTestCase $testCase, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->findAgentOrFail($agentId);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_test_case_run_'.$testCase->getId());

        $result = $this->testRunner->runCase($testCase);

        if ('passed' === $result->status) {
            $this->addFlash('success', $this->translator->trans('synapse.admin.test_case.flash.run_passed', [
                'name' => $testCase->getName(),
                'duration' => number_format($result->durationSeconds, 2),
            ], 'synapse_admin'));
        } elseif ('error' === $result->status) {
            $this->addFlash('error', $this->translator->trans('synapse.admin.test_case.flash.run_error', [
                'name' => $testCase->getName(),
                'error' => $result->errorMessage ?? 'Unknown error',
            ], 'synapse_admin'));
        } else {
            $failures = [];
            foreach ($result->assertionResults as $assertion) {
                if (false === $assertion['passed']) {
                    $failures[] = $assertion['name'].': '.($assertion['reason'] ?? '');
                }
            }
            $this->addFlash('warning', $this->translator->trans('synapse.admin.test_case.flash.run_failed', [
                'name' => $testCase->getName(),
                'failures' => implode(' | ', $failures),
            ], 'synapse_admin'));
        }

        return $this->redirectToRoute('synapse_admin_agent_test_cases', ['agentId' => $agentId]);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────────

    private function findAgentOrFail(int $agentId): SynapseAgent
    {
        $agent = $this->agentRepo->find($agentId);
        if (null === $agent) {
            throw new NotFoundHttpException(sprintf('Agent #%d not found.', $agentId));
        }

        return $agent;
    }

    /**
     * Applique les données POST et retourne les erreurs de validation.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, string>
     */
    private function applyFormData(SynapseAgentTestCase $testCase, array $data): array
    {
        $errors = [];

        $nameVal = $data['name'] ?? null;
        if (is_string($nameVal)) {
            $name = trim($nameVal);
            if ('' === $name) {
                $errors['name'] = $this->translator->trans('synapse.admin.test_case.validation.name_required', [], 'synapse_admin');
            } else {
                $testCase->setName($name);
            }
        }

        $messageVal = $data['message'] ?? null;
        if (is_string($messageVal)) {
            $testCase->setMessage(trim($messageVal));
        }

        $assertionsVal = $data['assertions'] ?? null;
        if (is_string($assertionsVal) && '' !== trim($assertionsVal)) {
            try {
                $decoded = json_decode($assertionsVal, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($decoded)) {
                    $errors['assertions'] = $this->translator->trans('synapse.admin.test_case.validation.assertions_not_object', [], 'synapse_admin');
                } else {
                    $testCase->setAssertions($decoded);
                }
            } catch (\JsonException $e) {
                $errors['assertions'] = $this->translator->trans('synapse.admin.test_case.validation.assertions_invalid_json', ['message' => $e->getMessage()], 'synapse_admin');
            }
        } elseif (is_string($assertionsVal) && '' === trim($assertionsVal)) {
            $testCase->setAssertions([]);
        }

        $sortOrder = $data['sort_order'] ?? null;
        if (is_numeric($sortOrder)) {
            $testCase->setSortOrder((int) $sortOrder);
        }

        $testCase->setIsActive(isset($data['is_active']));

        if ('' === $testCase->getMessage() && [] === $testCase->getStructuredInput()) {
            $errors['message'] = $this->translator->trans('synapse.admin.test_case.validation.message_required', [], 'synapse_admin');
        }

        return $errors;
    }
}
