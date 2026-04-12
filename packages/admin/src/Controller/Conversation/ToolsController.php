<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Controller\Conversation;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Engine\ToolConfigService;
use ArnaudMoncondhuy\SynapseCore\Engine\ToolRegistry;
use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\ToolAvailability;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Catalogue des outils (Function Calling) exposés à l'IA — Administration Synapse.
 *
 * Les outils sont définis en code via AiToolInterface, mais l'admin peut
 * contrôler leur disponibilité runtime (désactivé / actif / agent seul) via
 * SynapseToolConfig. À chaque affichage de l'index, la table est synchronisée
 * avec le registre DI (création des nouveaux outils, suppression des orphelins).
 */
#[Route('%synapse.admin_prefix%/conversation/outils', name: 'synapse_admin_')]
class ToolsController extends AbstractController
{
    use AdminSecurityTrait;

    public function __construct(
        private readonly ToolRegistry $toolRegistry,
        private readonly ToolConfigService $toolConfigService,
        private readonly PermissionCheckerInterface $permissionChecker,
        private readonly ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ) {
    }

    #[Route('', name: 'tools', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        // Synchronise la table avec le registre DI (outils ajoutés/retirés du code).
        $syncReport = $this->toolConfigService->sync();
        if ([] !== $syncReport['created']) {
            $this->addFlash('info', sprintf(
                'Nouveaux outils détectés et ajoutés au catalogue : %s.',
                implode(', ', $syncReport['created'])
            ));
        }
        if ([] !== $syncReport['removed']) {
            $this->addFlash('warning', sprintf(
                'Outils disparus du code et retirés du catalogue : %s.',
                implode(', ', $syncReport['removed'])
            ));
        }

        $availabilityMap = $this->toolConfigService->getAvailabilityMap();
        $tools = $this->toolRegistry->getTools();

        $toolsData = [];
        $countByAvailability = [
            ToolAvailability::ACTIVE->value => 0,
            ToolAvailability::AGENT_ONLY->value => 0,
            ToolAvailability::DISABLED->value => 0,
        ];
        foreach ($tools as $tool) {
            $availability = $availabilityMap[$tool->getName()] ?? ToolAvailability::ACTIVE;
            $toolsData[] = [
                'name' => $tool->getName(),
                'label' => $tool->getLabel(),
                'description' => $tool->getDescription(),
                'class' => get_class($tool),
                'availability' => $availability,
            ];
            ++$countByAvailability[$availability->value];
        }

        return $this->render('@Synapse/admin/conversation/tools.html.twig', [
            'tools' => $toolsData,
            'availabilities' => ToolAvailability::cases(),
            'counts' => $countByAvailability,
        ]);
    }

    /**
     * Met à jour le niveau de disponibilité d'un outil.
     */
    #[Route('/{toolName}/availability', name: 'tools_set_availability', methods: ['POST'], requirements: ['toolName' => '[a-zA-Z0-9_\-\.]+'])]
    public function setAvailability(string $toolName, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_tool_availability_'.$toolName);

        if (!$this->toolRegistry->has($toolName)) {
            throw $this->createNotFoundException(sprintf('Outil "%s" inconnu.', $toolName));
        }

        $value = $request->request->get('availability');
        if (!is_string($value)) {
            $this->addFlash('error', 'Valeur de disponibilité manquante.');

            return $this->redirectToRoute('synapse_admin_tools');
        }

        $availability = ToolAvailability::tryFrom($value);
        if (null === $availability) {
            $this->addFlash('error', sprintf('Valeur de disponibilité invalide : %s.', $value));

            return $this->redirectToRoute('synapse_admin_tools');
        }

        $this->toolConfigService->setAvailability($toolName, $availability);

        $this->addFlash('success', sprintf(
            'Outil "%s" → %s.',
            $this->toolRegistry->getLabel($toolName),
            $availability->label()
        ));

        return $this->redirectToRoute('synapse_admin_tools');
    }
}
