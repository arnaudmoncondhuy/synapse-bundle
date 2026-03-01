<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Admin\Controller\Conversation;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Core\Chat\ToolRegistry;
use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Catalogue des outils (Function Calling) exposés à l'IA — Administration Synapse
 *
 * Les outils sont uniquement en lecture : ils sont définis en code via ToolInterface.
 * Cette page sert de référence pour les développeurs et les admins.
 */
#[Route('/synapse/admin/conversation/outils', name: 'synapse_admin_')]
class ToolsController extends AbstractController
{
    use AdminSecurityTrait;

    public function __construct(
        private ToolRegistry $toolRegistry,
        private PermissionCheckerInterface $permissionChecker,
    ) {}

    #[Route('', name: 'tools', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $tools = $this->toolRegistry->getTools();

        $toolsData = array_map(fn($tool) => [
            'name'        => $tool->getName(),
            'description' => $tool->getDescription(),
            'class'       => get_class($tool),
        ], $tools);

        return $this->render('@Synapse/admin/conversation/tools.html.twig', [
            'tools' => $toolsData,
        ]);
    }
}
