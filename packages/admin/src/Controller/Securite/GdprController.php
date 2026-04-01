<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Controller\Securite;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Manager\ConversationManager;
use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use ArnaudMoncondhuy\SynapseCore\Security\RgpdEvaluator;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\RgpdInfo;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseConfigRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelPresetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Dashboard RGPD — Administration Synapse.
 *
 * Centralise les informations liées à la conformité RGPD :
 * - Politique de rétention active
 * - Statistiques de données stockées
 * - Avertissements sur les presets dont le provider signale un risque RGPD
 */
#[Route('%synapse.admin_prefix%/securite/rgpd', name: 'synapse_admin_')]
class GdprController extends AbstractController
{
    use AdminSecurityTrait;

    public function __construct(
        private readonly SynapseConfigRepository $configRepo,
        private readonly ConversationManager $conversationManager,
        private readonly PermissionCheckerInterface $permissionChecker,
        private readonly EntityManagerInterface $em,
        private readonly SynapseModelPresetRepository $presetRepo,
        private readonly RgpdEvaluator $rgpdEvaluator,
        private readonly ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ) {
    }

    #[Route('', name: 'gdpr', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $config = $this->configRepo->getGlobalConfig();

        if ($request->isMethod('POST')) {
            $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_admin_gdpr');

            $retentionDays = (int) ($request->request->get('retention_days') ?? 30);
            if ($retentionDays >= 1 && $retentionDays <= 3650) {
                $config->setRetentionDays($retentionDays);
                $this->em->flush();
                $this->addFlash('success', 'La durée de rétention a été mise à jour.');
            }

            return $this->redirectToRoute('synapse_admin_gdpr');
        }

        $totalConversations = $this->conversationManager->countAllConversations();

        return $this->render('@Synapse/admin/securite/gdpr.html.twig', [
            'config' => $config,
            'total_conversations' => $totalConversations,
            'rgpd_warnings' => $this->buildRgpdWarnings(),
        ]);
    }

    /**
     * @return array<int, array{preset: \ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseModelPreset, rgpd: RgpdInfo}>
     */
    private function buildRgpdWarnings(): array
    {
        return $this->rgpdEvaluator->getWarnings($this->presetRepo->findAllPresets());
    }
}
