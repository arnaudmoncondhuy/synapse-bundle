<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\AdminV2\Controller;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Contrôleur de substitution pour les pages "Coming Soon" de l'Admin V2.
 *
 * Couvre deux cas :
 * 1. Pages à migrer depuis la V1 (providers, models, presets…) — placeholder temporaire
 * 2. Pages totalement nouvelles (personas, quotas, rgpd…) — placeholder long terme
 */
#[Route('/synapse/admin-v2', name: 'synapse_v2_admin_')]
class PlaceholderController extends AbstractController
{
    use AdminSecurityTrait;

    public function __construct(
        private readonly PermissionCheckerInterface $permissionChecker,
    ) {}

    // ─── Intelligence — À MIGRER depuis V1 ────────────────────────────────────

    // Désactivé car migré vers Intelligence/ProviderController.php
    /*
    #[Route('/intelligence/fournisseurs', name: 'providers')]
    public function providers(): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        return $this->placeholder('Fournisseurs', 'plug', 'Intelligence', 'Configurez les credentials de vos providers LLM (Gemini, OVH, etc.). Migration V2 en cours.');
    }
    */

    // Désactivé car migré vers Intelligence/ModelController.php
    /*
    #[Route('/intelligence/modeles', name: 'models')]
    public function models(): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        return $this->placeholder('Modèles', 'cpu', 'Intelligence', 'Catalogue des modèles LLM disponibles — activation, pricing et configuration. Migration V2 en cours.');
    }
    */

    // Désactivé car migré vers Intelligence/PresetController.php
    /*
    #[Route('/intelligence/presets', name: 'presets')]
    public function presets(): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        return $this->placeholder('Presets', 'sliders-horizontal', 'Intelligence', 'Configurez vos presets de génération LLM (température, top-p, max tokens…). Migration V2 en cours.');
    }
    */

    // Désactivé car migré vers Intelligence/ToneController.php
    /*
    #[Route('/intelligence/personas', name: 'personas')]
    public function personas(): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        return $this->placeholder('Personas', 'user-circle', 'Intelligence', 'Gérez les identités et rôles de votre IA. Définissez des personnalités, tons de voix et prompts système par persona.');
    }
    */

    // ─── Conversation — À MIGRER depuis V1 ────────────────────────────────────

    // Désactivé car migré vers Conversation/SettingsController.php
    /*
    #[Route('/conversation/parametres', name: 'settings')]
    public function settings(): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        return $this->placeholder('Paramètres', 'message-square-more', 'Conversation', 'Paramètres globaux : langue, prompt système, rétention RGPD, mode debug. Migration V2 en cours.');
    }
    */

    // Désactivé car migré vers Conversation/ToolsController.php
    /*
    #[Route('/conversation/outils', name: 'tools')]
    public function tools(): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        return $this->placeholder('Outils', 'wrench', 'Conversation', 'Catalogue des function calls exposés à l\'IA (tools). Migration V2 en cours.');
    }
    */

    // Désactivé car migré vers Conversation/HistoryController.php
    /*
    #[Route('/conversation/historique', name: 'history')]
    public function history(): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        return $this->placeholder('Historique', 'messages-square', 'Conversation', 'Consultez et recherchez parmi toutes les conversations. Accès Break-Glass avec audit automatique.');
    }
    */

    // ─── Mémoire — À MIGRER depuis V1 (sauf documents/memories = nouveau) ─────

    // Désactivé car migré vers Memoire/EmbeddingController.php
    /*
    #[Route('/memoire/embeddings', name: 'embeddings')]
    public function embeddings(): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        return $this->placeholder('Embeddings', 'database-zap', 'Mémoire', 'Paramétrez votre base vectorielle, modèle d\'embedding et stratégie de chunking. Migration V2 en cours.');
    }
    */

    #[Route('/memoire/documents', name: 'documents')]
    public function documents(): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        return $this->placeholder('Documents', 'files', 'Mémoire', 'Gérez les documents sources indexés dans la base vectorielle pour le RAG.');
    }

    // Désactivé car migré vers Memoire/MemoryController.php
    /*
    #[Route('/memoire/souvenirs', name: 'memories')]
    public function memories(): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        return $this->placeholder('Souvenirs', 'sparkles', 'Mémoire', 'Visualisez et gérez les mémoires confirmées par vos utilisateurs — backend disponible dans la V2.');
    }
    */

    // ─── Usage — À MIGRER depuis V1 (sauf quotas/export = nouveau) ────────────

    // Désactivé car migré vers Usage/AnalyticsController.php
    /*
    #[Route('/usage/analytics', name: 'analytics')]
    public function analytics(): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        return $this->placeholder('Analytics', 'bar-chart-3', 'Usage', 'Statistiques d\'usage détaillées : tokens, coûts, utilisateurs actifs. Migration V2 en cours.');
    }
    */

    // Désactivé — migré vers Usage/QuotasController.php
    /*
    #[Route('/usage/quotas', name: 'quotas')]
    public function quotas(): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        return $this->placeholder('Quotas', 'gauge', 'Usage', 'Définissez des limites de tokens par utilisateur, équipe ou globalement pour maîtriser vos coûts.');
    }
    */

    #[Route('/usage/export', name: 'export')]
    public function export(): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        return $this->placeholder('Export', 'download', 'Usage', 'Exportez vos données d\'usage au format CSV ou JSON pour vos rapports et analyses externes.');
    }

    // ─── Sécurité ──────────────────────────────────────────────────────────────

    // Désactivé — migré vers Securite/AuditController.php
    /*
    #[Route('/securite/audit', name: 'audit')]
    public function audit(): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        return $this->placeholder('Audit & Logs', 'scroll-text', 'Sécurité', 'Journal des logs de debug et historique des accès Break-Glass.');
    }
    */

    // Désactivé — migré vers Securite/ApiKeysController.php
    /*
    #[Route('/securite/cles-api', name: 'api_keys')]
    public function apiKeys(): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        return $this->placeholder('Clés API', 'key-round', 'Sécurité', 'Gestion des secrets et clés API.');
    }
    */

    // Désactivé — migré vers Securite/GdprController.php
    /*
    #[Route('/securite/rgpd', name: 'gdpr')]
    public function gdpr(): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        return $this->placeholder('RGPD', 'shield-check', 'Sécurité', 'Conformité RGPD, rétention et purges de données.');
    }
    */

    // ─── Système ───────────────────────────────────────────────────────────────

    // Désactivé — migré vers Systeme/DebugController.php
    /*
    #[Route('/systeme/debug', name: 'debug')]
    public function debug(): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        return $this->placeholder('Debug', 'bug', 'Système', 'Rapports de debug détaillés des échanges LLM.');
    }
    */

    // Désactivé — migré vers Systeme/HealthController.php
    /*
    #[Route('/systeme/sante', name: 'health')]
    public function health(): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        return $this->placeholder('Santé', 'heart-pulse', 'Système', 'État des composants critiques.');
    }
    */

    // Désactivé — migré vers Systeme/AboutController.php
    /*
    #[Route('/systeme/a-propos', name: 'about')]
    public function about(): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        return $this->placeholder('À propos', 'info', 'Système', 'Version du bundle, dépendances et changelog.');
    }
    */

    // ─── Helper ────────────────────────────────────────────────────────────────

    private function placeholder(
        string $title,
        string $icon,
        string $section,
        string $message,
    ): Response {
        return $this->render('@Synapse/admin_v2/shared/placeholder.html.twig', [
            'page_title'           => $title,
            'icon'                 => $icon,
            'breadcrumb_section'   => $section,
            'coming_soon_message'  => $message,
        ]);
    }
}
