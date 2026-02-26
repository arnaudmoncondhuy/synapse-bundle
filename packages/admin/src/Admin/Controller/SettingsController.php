<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Admin\Controller;

use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseConfigRepository;
use ArnaudMoncondhuy\SynapseCore\Core\DatabaseConfigProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Gestion des paramètres globaux de Synapse
 *
 * Préset, langue du contexte, rétention RGPD, prompt système personnalisé.
 */
#[Route('/synapse/admin/settings')]
class SettingsController extends AbstractController
{
    use AdminSecurityTrait;

    public function __construct(
        private SynapseConfigRepository $configRepo,
        private DatabaseConfigProvider $configProvider,
        private EntityManagerInterface $em,
        private PermissionCheckerInterface $permissionChecker,
        private ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ) {}

    /**
     * Afficher et éditer les paramètres globaux
     */
    #[Route('', name: 'synapse_admin_settings', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $config = $this->configRepo->getGlobalConfig();

        if ($request->isMethod('POST')) {
            $this->validateCsrfToken($request, $this->csrfTokenManager);
            // Retention RGPD
            $retentionDays = (int) ($request->request->get('retention_days') ?? 30);
            if ($retentionDays >= 1 && $retentionDays <= 3650) { // 1 day to 10 years
                $config->setRetentionDays($retentionDays);
            }

            // Langue du contexte
            $language = $request->request->get('context_language', 'fr');
            if (in_array($language, ['fr', 'en', 'es', 'de', 'it', 'pt', 'nl', 'ru', 'zh', 'ja', 'ko'], true)) {
                $config->setContextLanguage($language);
            }

            // Prompt système personnalisé
            $systemPrompt = $request->request->get('system_prompt');
            $config->setSystemPrompt(!empty($systemPrompt) ? $systemPrompt : null);

            // Mode debug global
            $config->setDebugMode($request->request->getBoolean('debug_mode'));

            $this->em->flush();

            // Invalider le cache de configuration
            $this->configProvider->clearCache();

            $this->addFlash('success', 'Paramètres enregistrés avec succès.');

            return $this->redirectToRoute('synapse_admin_settings');
        }

        return $this->render('@Synapse/admin/settings.html.twig', [
            'config' => $config,
            'languages' => $this->getAvailableLanguages(),
        ]);
    }

    private function getAvailableLanguages(): array
    {
        return [
            'fr' => 'Français',
            'en' => 'English',
            'es' => 'Español',
            'de' => 'Deutsch',
            'it' => 'Italiano',
            'pt' => 'Português',
            'nl' => 'Nederlands',
            'ru' => 'Русский',
            'zh' => '中文',
            'ja' => '日本語',
            'ko' => '한국어',
        ];
    }
}
