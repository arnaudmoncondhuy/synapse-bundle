<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Admin\Controller;

use ArnaudMoncondhuy\SynapseBundle\Core\Chat\ToolRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Contrôleur pour la gestion et la visualisation des outils (Tools).
 */
#[Route('/synapse/admin/tools')]
class ToolsController extends AbstractController
{
    public function __construct(
        private ToolRegistry $toolRegistry
    ) {}

    /**
     * Liste tous les outils exposés au bundle (vue compacte).
     */
    #[Route('', name: 'synapse_admin_tools', methods: ['GET'])]
    public function index(): Response
    {
        $tools = $this->toolRegistry->getTools();

        $toolsData = [];
        foreach ($tools as $tool) {
            $toolsData[] = [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'class' => get_class($tool),
            ];
        }

        return $this->render('@Synapse/admin/tools.html.twig', [
            'tools' => $toolsData,
        ]);
    }

    /**
     * Affiche les détails d'un outil spécifique.
     */
    #[Route('/{name}', name: 'synapse_admin_tools_show', methods: ['GET'])]
    public function show(string $name): Response
    {
        $tool = $this->toolRegistry->get($name);

        if (!$tool) {
            throw $this->createNotFoundException('Outil non trouvé.');
        }

        return $this->render('@Synapse/admin/tool_show.html.twig', [
            'tool' => [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'schema' => $tool->getInputSchema(),
                'class' => get_class($tool),
            ],
        ]);
    }
}
