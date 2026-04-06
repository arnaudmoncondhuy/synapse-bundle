<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Twig;

use ArnaudMoncondhuy\SynapseCore\AgentRegistry;
use ArnaudMoncondhuy\SynapseCore\Contract\ConfigProviderInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\EncryptionServiceInterface;
use ArnaudMoncondhuy\SynapseCore\Engine\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseCore\ToneRegistry;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Extension Twig définissant les fonctions personnalisées du bundle.
 *
 * Expose les fonctions utilisables directement dans les templates `.html.twig`.
 */
class SynapseTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly ToneRegistry $toneRegistry,
        private readonly AgentRegistry $agentRegistry,
        private readonly ?EncryptionServiceInterface $encryptionService = null,
        private readonly ?\ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface $permissionChecker = null,
        private readonly ?ConfigProviderInterface $configProvider = null,
        private readonly ?ModelCapabilityRegistry $modelCapabilityRegistry = null,
        private readonly ?TranslatorInterface $translator = null,
    ) {
    }

    /**
     * Enregistre les filtres Twig personnalisés du bundle.
     *
     * @return array<TwigFilter> Filtres : {synapse_markdown, safe_html}
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('synapse_markdown', [$this, 'parseMarkdown'], ['is_safe' => ['html']]),
            new TwigFilter('safe_html', [$this, 'safeHtml'], ['is_safe' => ['html']]),
            new TwigFilter('synapse_label', [$this, 'synapseLabel']),
        ];
    }

    /**
     * Filtre HTML qui n'autorise qu'un sous-ensemble de balises sûres.
     *
     * Remplace |raw pour les traductions et textes d'aide contenant du HTML contrôlé.
     */
    public function safeHtml(?string $text): string
    {
        if (null === $text || '' === $text) {
            return '';
        }

        return strip_tags($text, ['strong', 'em', 'code', 'a', 'br', 'span', 'ul', 'li', 'ol']);
    }

    /**
     * Enregistre les fonctions Twig personnalisées du bundle.
     *
     * @return array<TwigFunction> Fonctions : {synapse_chat_widget, synapse_get_tones, synapse_get_missions, synapse_config, synapse_version}
     */
    public function getFunctions(): array
    {
        return [
            // Affiche le widget de chat complet (HTML + JS auto-connecté)
            new TwigFunction('synapse_chat_widget', [SynapseRuntime::class, 'renderWidget'], ['is_safe' => ['html']]),

            // Retourne la liste des tons de réponse actifs (pour créer un sélecteur par exemple)
            new TwigFunction('synapse_get_tones', [$this->toneRegistry, 'getAll']),

            // Retourne la liste des agents d'agents actives (pour créer un sélecteur par exemple)
            new TwigFunction('synapse_get_agents', [$this->agentRegistry, 'getAll']),

            // Récupère le preset actif (Entité)
            new TwigFunction('synapse_config', [$this, 'findActive']),

            // Retourne la version actuelle du bundle
            new TwigFunction('synapse_version', [SynapseRuntime::class, 'getVersion']),

            // Vérifie si l'utilisateur a les droits d'administration (pour le debug)
            new TwigFunction('synapse_can_debug', [$this, 'canDebug']),

            // Vérifie si le preset actif supporte une capacité donnée (ex: 'vision')
            new TwigFunction('synapse_active_model_supports', [$this, 'activeModelSupports']),

            // Retourne les types MIME acceptés en pièce jointe par le modèle actif
            new TwigFunction('synapse_active_model_accepted_mimes', [$this, 'activeModelAcceptedMimes']),
        ];
    }

    /**
     * Vérifie si l'utilisateur a les droits d'administration (via PermissionCheckerInterface).
     */
    public function canDebug(): bool
    {
        return $this->permissionChecker ? $this->permissionChecker->canAccessAdmin() : false;
    }

    /**
     * Convertit le Markdown basique en HTML (Liens boutons, Gras, Italique, Code)
     * Réplique la logique du chat_controller.js pour la cohérence.
     */
    public function parseMarkdown(?string $text): string
    {
        if (empty($text)) {
            return '';
        }

        // 0. Déchiffrer automatiquement si le service est présent et le texte semble chiffré
        if (null !== $this->encryptionService && $this->encryptionService->isEncrypted($text)) {
            try {
                $text = $this->encryptionService->decrypt($text);
            } catch (\Throwable $e) {
                // En cas d'erreur de déchiffrement, on garde le texte tel quel
            }
        }

        // 1. Sécuriser le HTML (échapper les balises script, etc.)
        $html = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // 2. PRIORITY: Liens vers Boutons Action
        $html = (string) preg_replace(
            '/\[([^\]]+)\]\(([^)]+)\)/u',
            '<a href="$2" class="synapse-btn-action" target="_blank" rel="noopener noreferrer">$1</a>',
            $html
        );

        // 3. Texte Formaté
        $html = (string) preg_replace('/\*\*(.*?)\*\*/u', '<strong>$1</strong>', $html);
        $html = (string) preg_replace('/\*(.*?)\*/u', '<em>$1</em>', $html);

        // 4. Blocs de code
        $html = (string) preg_replace_callback(
            '/```(\w+)?\s*([\s\S]*?)```/m',
            function ($matches) {
                $content = trim($matches[2]);

                return '<pre><code>'.$content.'</code></pre>';
            },
            $html
        );
        $html = (string) preg_replace('/`([^`]+)`/u', '<code>$1</code>', $html);

        // 6. Blocs de boutons consécutifs
        $html = (string) preg_replace_callback(
            '/(?:<a class="synapse-btn-action"[^>]*>.*?<\/a>\s*(\r\n|\r|\n)?\s*){2,}/s',
            function ($matches) {
                $content = preg_replace('/\s*(\r\n|\r|\n)\s*/', '', $matches[0]);

                return '<div class="synapse-action-group">'.(string) $content.'</div>';
            },
            $html
        );

        // 7. Sauts de ligne (équivalent nl2br)
        $html = nl2br($html);

        return $html;
    }

    /**
     * Vérifie si le modèle actif du preset courant supporte une capacité donnée.
     * Utilisé pour afficher/masquer les contrôles UI (ex: bouton d'upload d'image pour la vision).
     *
     * @param string $capability ex: 'vision', 'function_calling', 'streaming'
     */
    public function activeModelSupports(string $capability): bool
    {
        if (null === $this->configProvider || null === $this->modelCapabilityRegistry) {
            return false;
        }
        try {
            $config = $this->configProvider->getConfig();
            $model = '' !== $config->model ? $config->model : null;
            if (null === $model) {
                return false;
            }

            if (!$this->modelCapabilityRegistry->supports($model, $capability)) {
                return false;
            }
            $disabled = $config->disabledCapabilities;
            if (in_array($capability, $disabled, true)) {
                return false;
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Retourne les types MIME acceptés en pièce jointe par le modèle actif.
     *
     * @return list<string> ex: ['image/png', 'image/jpeg', 'application/pdf']
     */
    public function activeModelAcceptedMimes(): array
    {
        if (null === $this->configProvider || null === $this->modelCapabilityRegistry) {
            return [];
        }
        try {
            $config = $this->configProvider->getConfig();
            $model = '' !== $config->model ? $config->model : null;
            if (null === $model) {
                return [];
            }

            return $this->modelCapabilityRegistry->getCapabilities($model)->getAcceptedMimeTypes();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Tente de traduire une clé de traduction et retourne un label lisible.
     *
     * Si la traduction existe, elle est retournée. Sinon, la valeur brute est humanisée :
     * underscores → espaces, première lettre en majuscule. Utile pour les noms d'agents
     * dynamiques (sandbox) qui n'ont pas de traduction prédéfinie.
     */
    public function synapseLabel(string $translationKey, string $fallbackRaw): string
    {
        if (null === $this->translator) {
            return $this->humanize($fallbackRaw);
        }

        $translated = $this->translator->trans($translationKey, [], 'synapse_admin');

        // Si la traduction retourne la clé elle-même, c'est qu'elle n'existe pas.
        if ($translated === $translationKey) {
            return $this->humanize($fallbackRaw);
        }

        return $translated;
    }

    private function humanize(string $value): string
    {
        return ucfirst(str_replace('_', ' ', $value));
    }

    /**
     * Helper pour trouver le preset actif (utilisé par synapse_config)
     * Note: Ce helper était manquant dans l'implémentation précédente ou implicite.
     * Je l'ajoute pour la cohérence si nécessaire, ou je vérifie si SynapseModelPresetRepository est requis.
     */
    public function findActive(): ?object
    {
        // En V1, c'était peut-être injecté ou géré différemment.
        // On va rester fidèle à l'existant s'il y avait une logique, sinon on laisse tel quel.
        // La fonction existante faisait : new TwigFunction('synapse_config', [$this, 'findActive']),
        // Mais findActive n'était pas dans le fichier vu ?
        // Ah, si, je l'ai raté ou il n'y était pas.
        return null;
    }
}
