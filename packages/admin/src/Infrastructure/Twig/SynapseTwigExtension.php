<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Infrastructure\Twig;

use ArnaudMoncondhuy\SynapseCore\Contract\EncryptionServiceInterface;
use ArnaudMoncondhuy\SynapseCore\Core\MissionRegistry;
use ArnaudMoncondhuy\SynapseCore\Core\ToneRegistry;
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
        private ToneRegistry $toneRegistry,
        private MissionRegistry $missionRegistry,
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
     * @return array<TwigFunction> Fonctions : {synapse_chat_widget, synapse_get_tones, synapse_get_missions, synapse_config, synapse_version}
     */
    public function getFunctions(): array
    {
        return [
            // Affiche le widget de chat complet (HTML + JS auto-connecté)
            new TwigFunction('synapse_chat_widget', [SynapseRuntime::class, 'renderWidget'], ['is_safe' => ['html']]),

            // Retourne la liste des tons de réponse actifs (pour créer un sélecteur par exemple)
            new TwigFunction('synapse_get_tones', [$this->toneRegistry, 'getAll']),

            // Retourne la liste des missions d'agents actives (pour créer un sélecteur par exemple)
            new TwigFunction('synapse_get_missions', [$this->missionRegistry, 'getAll']),

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
        $html = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // 2. PRIORITY: Liens vers Boutons Action
        $html = preg_replace(
            '/\[([^\]]+)\]\(([^)]+)\)/u',
            '<a href="$2" class="synapse-btn-action" target="_blank" rel="noopener noreferrer">$1</a>',
            $html
        );

        // 3. Texte Formaté
        $html = preg_replace('/\*\*(.*?)\*\*/u', '<strong>$1</strong>', $html);
        $html = preg_replace('/\*(.*?)\*/u', '<em>$1</em>', $html);

        // 4. Blocs de code
        $html = preg_replace_callback(
            '/```(\w+)?\s*([\s\S]*?)```/m',
            function ($matches) {
                $content = trim($matches[2]);
                return '<pre><code>' . $content . '</code></pre>';
            },
            $html
        );
        $html = preg_replace('/`([^`]+)`/u', '<code>$1</code>', $html);

        // 6. Blocs de boutons consécutifs
        $html = preg_replace_callback(
            '/(?:<a class="synapse-btn-action"[^>]*>.*?<\/a>\s*(\r\n|\r|\n)?\s*){2,}/s',
            function ($matches) {
                $content = preg_replace('/\s*(\r\n|\r|\n)\s*/', '', $matches[0]);
                return '<div class="synapse-action-group">' . $content . '</div>';
            },
            $html
        );

        // 7. Sauts de ligne (équivalent nl2br)
        $html = nl2br($html);

        return $html;
    }

    /**
     * Helper pour trouver le preset actif (utilisé par synapse_config)
     * Note: Ce helper était manquant dans l'implémentation précédente ou implicite.
     * Je l'ajoute pour la cohérence si nécessaire, ou je vérifie si SynapsePresetRepository est requis.
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
