<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Controller\Systeme;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseConfigRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseProviderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Santé du système — Administration Synapse.
 *
 * Vérifie l'état des composants critiques :
 * - Connexion base de données
 * - Providers LLM configurés
 * - Configuration Synapse (embedding, debug)
 */
#[Route('%synapse.admin_prefix%/systeme/sante', name: 'synapse_admin_')]
class HealthController extends AbstractController
{
    use AdminSecurityTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SynapseProviderRepository $providerRepo,
        private readonly SynapseConfigRepository $configRepo,
        private readonly PermissionCheckerInterface $permissionChecker,
        private readonly TranslatorInterface $translator,
    ) {
    }

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
                'label' => $this->translator->trans('synapse.admin.health.check.database.label', [], 'synapse_admin'),
                'status' => 'ok',
                'detail' => $platform,
                'icon' => 'database',
            ];
        } catch (\Exception $e) {
            $checks['database'] = [
                'label' => $this->translator->trans('synapse.admin.health.check.database.label', [], 'synapse_admin'),
                'status' => 'error',
                'detail' => $e->getMessage(),
                'icon' => 'database',
            ];
        }

        // ── Providers LLM ─────────────────────────────────────────────────────
        try {
            $providers = $this->providerRepo->findAllOrdered();
            $enabled = array_filter($providers, fn ($p) => $p->isEnabled());
            $configured = array_filter($providers, fn ($p) => $p->isConfigured());

            $checks['providers'] = [
                'label' => $this->translator->trans('synapse.admin.health.check.providers.label', [], 'synapse_admin'),
                'status' => count($configured) > 0 ? 'ok' : 'warning',
                'detail' => $this->translator->trans(
                    'synapse.admin.health.check.providers.detail',
                    ['enabled' => count($enabled), 'configured' => count($configured), 'total' => count($providers)],
                    'synapse_admin'
                ),
                'icon' => 'plug',
            ];
        } catch (\Exception $e) {
            $checks['providers'] = [
                'label' => $this->translator->trans('synapse.admin.health.check.providers.label', [], 'synapse_admin'),
                'status' => 'error',
                'detail' => $e->getMessage(),
                'icon' => 'plug',
            ];
        }

        // ── Configuration Synapse ─────────────────────────────────────────────
        try {
            $config = $this->configRepo->getGlobalConfig();
            $checks['config'] = [
                'label' => $this->translator->trans('synapse.admin.health.check.config.label', [], 'synapse_admin'),
                'status' => 'ok',
                'detail' => $this->translator->trans(
                    'synapse.admin.health.check.config.detail',
                    [
                        'lang' => $config->getContextLanguage(),
                        'debug' => $config->isDebugMode() ? 'ON' : 'OFF',
                        'days' => $config->getRetentionDays(),
                    ],
                    'synapse_admin'
                ),
                'icon' => 'settings',
            ];

            $checks['embedding'] = [
                'label' => $this->translator->trans('synapse.admin.memory.embeddings.kpi.chunking_label', [], 'synapse_admin'),
                'status' => $config->getEmbeddingModel() ? 'ok' : 'warning',
                'detail' => $config->getEmbeddingModel()
                    ? $this->translator->trans(
                        'synapse.admin.health.check.embedding.detail',
                        ['model' => $config->getEmbeddingModel(), 'provider' => $config->getEmbeddingProvider()],
                        'synapse_admin'
                    )
                    : $this->translator->trans('synapse.admin.llm_config.provider.status.unconfigured', [], 'synapse_admin'),
                'icon' => 'database-zap',
            ];
        } catch (\Exception $e) {
            $checks['config'] = [
                'label' => $this->translator->trans('synapse.admin.health.check.config.label', [], 'synapse_admin'),
                'status' => 'error',
                'detail' => $e->getMessage(),
                'icon' => 'settings',
            ];
        }

        $overallStatus = 'ok';
        foreach ($checks as $check) {
            if ('error' === $check['status']) {
                $overallStatus = 'error';
                break;
            }
            if ('warning' === $check['status']) {
                $overallStatus = 'warning';
            }
        }

        return $this->render('@Synapse/admin/systeme/health.html.twig', [
            'checks' => $checks,
            'overall_status' => $overallStatus,
            'php_version' => PHP_VERSION,
            'symfony_version' => \Symfony\Component\HttpKernel\Kernel::VERSION,
        ]);
    }
}
