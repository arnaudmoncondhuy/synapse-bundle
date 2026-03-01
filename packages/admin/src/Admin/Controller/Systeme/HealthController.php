<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Admin\Controller\Systeme;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseProviderRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseConfigRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Santé du système — Administration Synapse
 *
 * Vérifie l'état des composants critiques :
 * - Connexion base de données
 * - Providers LLM configurés
 * - Configuration Synapse (embedding, debug)
 */
#[Route('/synapse/admin/systeme/sante', name: 'synapse_admin_')]
class HealthController extends AbstractController
{
    use AdminSecurityTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private SynapseProviderRepository $providerRepo,
        private SynapseConfigRepository $configRepo,
        private PermissionCheckerInterface $permissionChecker,
    ) {}

    #[Route('', name: 'health', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $checks = [];

        // ── Vérification base de données ──────────────────────────────────────
        try {
            $this->em->getConnection()->executeQuery('SELECT 1');
            $platform = (new \ReflectionClass($this->em->getConnection()->getDatabasePlatform()::class))->getShortName();
            $checks['database'] = [
                'label'    => 'Base de données',
                'status'   => 'ok',
                'detail'   => $platform,
                'icon'     => 'database',
            ];
        } catch (\Exception $e) {
            $checks['database'] = [
                'label'  => 'Base de données',
                'status' => 'error',
                'detail' => $e->getMessage(),
                'icon'   => 'database',
            ];
        }

        // ── Providers LLM ─────────────────────────────────────────────────────
        try {
            $providers = $this->providerRepo->findAllOrdered();
            $enabled   = array_filter($providers, fn($p) => $p->isEnabled());
            $configured = array_filter($providers, fn($p) => $p->isConfigured());

            $checks['providers'] = [
                'label'  => 'Providers LLM',
                'status' => count($configured) > 0 ? 'ok' : 'warning',
                'detail' => sprintf('%d actif(s), %d configuré(s) / %d total', count($enabled), count($configured), count($providers)),
                'icon'   => 'plug',
            ];
        } catch (\Exception $e) {
            $checks['providers'] = [
                'label'  => 'Providers LLM',
                'status' => 'error',
                'detail' => $e->getMessage(),
                'icon'   => 'plug',
            ];
        }

        // ── Configuration Synapse ─────────────────────────────────────────────
        try {
            $config = $this->configRepo->getGlobalConfig();
            $checks['config'] = [
                'label'  => 'Configuration Synapse',
                'status' => 'ok',
                'detail' => sprintf(
                    'Langue: %s | Debug: %s | Rétention: %dj',
                    $config->getContextLanguage(),
                    $config->isDebugMode() ? 'ON' : 'OFF',
                    $config->getRetentionDays(),
                ),
                'icon'   => 'settings',
            ];

            $checks['embedding'] = [
                'label'  => 'Embedding',
                'status' => $config->getEmbeddingModel() ? 'ok' : 'warning',
                'detail' => $config->getEmbeddingModel()
                    ? sprintf('%s via %s', $config->getEmbeddingModel(), $config->getEmbeddingProvider())
                    : 'Non configuré',
                'icon'   => 'database-zap',
            ];
        } catch (\Exception $e) {
            $checks['config'] = [
                'label'  => 'Configuration Synapse',
                'status' => 'error',
                'detail' => $e->getMessage(),
                'icon'   => 'settings',
            ];
        }

        $overallStatus = 'ok';
        foreach ($checks as $check) {
            if ($check['status'] === 'error') {
                $overallStatus = 'error';
                break;
            }
            if ($check['status'] === 'warning') {
                $overallStatus = 'warning';
            }
        }

        return $this->render('@Synapse/admin/systeme/health.html.twig', [
            'checks'           => $checks,
            'overall_status'   => $overallStatus,
            'php_version'      => PHP_VERSION,
            'symfony_version'  => \Symfony\Component\HttpKernel\Kernel::VERSION,
        ]);
    }
}
