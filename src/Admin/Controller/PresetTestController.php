<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Admin\Controller;

use ArnaudMoncondhuy\SynapseBundle\Core\Agent\PresetValidator\PresetValidatorAgent;
use ArnaudMoncondhuy\SynapseBundle\Storage\Entity\SynapsePreset;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Gère les tests de presets LLM depuis l'interface admin.
 */
class PresetTestController extends AbstractController
{
    public function __construct(
        private PresetValidatorAgent $agent,
    ) {
    }

    /**
     * Teste un preset et affiche le rapport d'analyse.
     */
    #[Route(
        path: '/synapse/admin/presets/{id}/test',
        name: 'synapse_admin_presets_test',
        methods: ['POST'],
    )]
    public function test(SynapsePreset $preset): Response
    {
        $report = $this->agent->run(['preset' => $preset]);

        return $this->render('@Synapse/admin/preset_test_result.html.twig', [
            'report' => $report,
        ]);
    }
}
