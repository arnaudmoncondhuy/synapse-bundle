<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Admin\Twig;

use ArnaudMoncondhuy\SynapseAdmin\Admin\Layout\SynapseLayoutResolver;
use ArnaudMoncondhuy\SynapseCore\Contract\EncryptionServiceInterface;
use ArnaudMoncondhuy\SynapseCore\Core\PersonaRegistry;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapsePresetRepository;
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
        private PersonaRegistry $personaRegistry,
        private SynapseLayoutResolver $layoutResolver,
        private SynapsePresetRepository $presetRepository,
        private ?EncryptionServiceInterface $encryptionService = null,
    ) {}

    /**
     * Enregistre les filtres Twig personnalisés du bundle.
     *
     * @return array<TwigFilter> Filtres : {synapse_markdown}
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('synapse_markdown', [$this, 'parseMarkdown'], ['is_safe' => ['html']]),
        ];
    }

    /**
     * Enregistre les fonctions Twig personnalisées du bundle.
     *
     * @return array<TwigFunction> Fonctions : {synapse_chat_widget, synapse_get_personas, synapse_admin_layout, synapse_config, synapse_version}
     */
    public function getFunctions(): array
    {
        return [
            // Affiche le widget de chat complet (HTML + JS auto-connecté)
            new TwigFunction('synapse_chat_widget', [SynapseRuntime::class, 'renderWidget'], ['is_safe' => ['html']]),

            // Retourne la liste des personas disponibles (pour créer un sélecteur par exemple)
            new TwigFunction('synapse_get_personas', [$this->personaRegistry, 'getAll']),

            // Résout dynamiquement le layout admin à utiliser (standalone ou module)
            new TwigFunction('synapse_admin_layout', [$this->layoutResolver, 'getAdminLayout']),

            // Récupère le preset actif (Entité)
            new TwigFunction('synapse_config', [$this, 'findActive']),

            // Retourne la version actuelle du bundle
            new TwigFunction('synapse_version', [SynapseRuntime::class, 'getVersion']),
        ];
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
        if ($this->encryptionService !== null && $this->encryptionService->isEncrypted($text)) {
            try {
                $text = $this->encryptionService->decrypt($text);
            } catch (\Throwable $e) {
                // En cas d'erreur de déchiffrement, on garde le texte tel quel
            }
        }

        // 1. Sécuriser le HTML (échapper les balises script, etc.)
        // On utilise htmlspecialchars mais on doit faire attention si le texte est déjà safe ou non.
        // Dans le contexte Twig, l'entrée est souvent brute.
        $html = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // 2. PRIORITY: Liens vers Boutons Action
        // Regex: [Label](URL) -> <a href="URL" class="synapse-btn-action">Label</a>
        $html = preg_replace(
            '/\[([^\]]+)\]\(([^)]+)\)/u',
            '<a href="$2" class="synapse-btn-action" target="_blank" rel="noopener noreferrer">$1</a>',
            $html
        );

        // 3. Texte Formaté
        // Gras: **text**
        $html = preg_replace('/\*\*(.*?)\*\*/u', '<strong>$1</strong>', $html);
        // Italique: *text*
        $html = preg_replace('/\*(.*?)\*/u', '<em>$1</em>', $html);

        // 4. Blocs de code
        // ```code``` -> <pre><code>code</code></pre>
        // Regex assouplie : supporte les blocs sur une seule ligne ou sans langage spécifique
        $html = preg_replace_callback(
            '/```(\w+)?\s*([\s\S]*?)```/m',
            function ($matches) {
                $content = trim($matches[2]);
                return '<pre><code>' . $content . '</code></pre>';
            },
            $html
        );
        // `code` -> <code>code</code>
        $html = preg_replace('/`([^`]+)`/u', '<code>$1</code>', $html);

        // 5. Detection automatique des groupes de boutons
        // On cherche 2+ boutons consécutifs (séparés ou non par des espaces/sauts de ligne)
        // Note: C'est plus complexe en regex pcre qu'en JS, on simplifie pour l'instant :
        // Si on trouve plusieurs liens côte à côte, on pourrait les wrapper, mais Twig nl2br va arriver ensuite.

        // 6. Blocs de boutons consécutifs
        // On cherche des motifs <a class="synapse-btn-action">...</a> suivis éventuellement de sauts de ligne, répétés
        // Pattern complexe, on tente une approche simple : si on a une suite de boutons, on les wrap.
        // (?:\s*<a class="synapse-btn-action".*?<\/a>\s*){2,}

        $html = preg_replace_callback(
            '/(?:<a class="synapse-btn-action"[^>]*>.*?<\/a>\s*(\r\n|\r|\n)?\s*){2,}/s',
            function ($matches) {
                // Nettoyer les sauts de ligne entre les boutons pour le flexbox
                $content = preg_replace('/\s*(\r\n|\r|\n)\s*/', '', $matches[0]);
                return '<div class="synapse-action-group">' . $content . '</div>';
            },
            $html
        );

        // 7. Sauts de ligne (équivalent nl2br)
        // On évite d'ajouter des BR dans les blocs <pre> ou autour des divs
        $html = nl2br($html);

        return $html;
    }
}
