<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Admin\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * @deprecated Redirige vers PresetsController.
 *
 * Conservé pour compatibilité avec les liens existants (synapse_admin_config).
 */
#[Route('/synapse/admin/config')]
class ConfigController extends AbstractController
{
    #[Route('', name: 'synapse_admin_config')]
    public function redirectToPresets(): Response
    {
        return $this->redirectToRoute('synapse_admin_presets');
    }
}
