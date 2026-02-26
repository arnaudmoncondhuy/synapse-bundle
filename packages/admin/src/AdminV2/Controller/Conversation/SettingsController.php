<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\AdminV2\Controller\Conversation;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Core\DatabaseConfigProvider;
use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseConfigRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Paramètres globaux Synapse (langue, prompt système, rétention RGPD, debug) — Admin V2
 *
 * Gère la table synapse_config (singleton).
 * Actions complexes (migrations, purge…) restent en V1.
 */
#[Route('/synapse/admin-v2/conversation/parametres', name: 'synapse_v2_admin_settings')]
class SettingsController extends AbstractController
{
    use AdminSecurityTrait;

    /** @var array<string, string> */
    private const LANGUAGES = [
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

    public function __construct(
        private SynapseConfigRepository $configRepo,
        private DatabaseConfigProvider $configProvider,
        private EntityManagerInterface $em,
        private PermissionCheckerInterface $permissionChecker,
        private ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ) {}

    /**
     * Afficher et modifier les paramètres globaux Synapse.
     */
    #[Route('', name: '', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $config = $this->configRepo->getGlobalConfig();

        if ($request->isMethod('POST')) {
            $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_v2_admin_settings');

            // Rétention RGPD (1 jour → 10 ans)
            $retentionDays = (int) ($request->request->get('retention_days') ?? 30);
            if ($retentionDays >= 1 && $retentionDays <= 3650) {
                $config->setRetentionDays($retentionDays);
            }

            // Langue
            $lang = $request->request->get('context_language', 'fr');
            if (array_key_exists($lang, self::LANGUAGES)) {
                $config->setContextLanguage($lang);
            }

            // Prompt système personnalisé
            $systemPrompt = $request->request->get('system_prompt');
            $config->setSystemPrompt(!empty($systemPrompt) ? $systemPrompt : null);

            // Mode debug
            $config->setDebugMode($request->request->getBoolean('debug_mode'));

            $this->em->flush();
            $this->configProvider->clearCache();

            $this->addFlash('success', 'Paramètres enregistrés avec succès.');

            return $this->redirectToRoute('synapse_v2_admin_settings');
        }

        return $this->render('@Synapse/admin_v2/conversation/settings.html.twig', [
            'config'    => $config,
            'languages' => self::LANGUAGES,
        ]);
    }
}
