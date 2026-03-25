<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Controller\Intelligence;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseTone;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseToneRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Gestion des tons de réponse de l'IA — Administration Synapse.
 *
 * Un ton définit le style de communication du LLM (registre, format, posture).
 */
#[Route('%synapse.admin_prefix%/intelligence/tones', name: 'synapse_admin_')]
class ToneController extends AbstractController
{
    use AdminSecurityTrait;

    public function __construct(
        private SynapseToneRepository $toneRepo,
        private EntityManagerInterface $em,
        private PermissionCheckerInterface $permissionChecker,
        private ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ) {
    }

    // ─── Index ─────────────────────────────────────────────────────────────────

    #[Route('', name: 'tones', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $tones = $this->toneRepo->findAllOrdered();

        return $this->render('@Synapse/admin/intelligence/tones.html.twig', [
            'tones' => $tones,
        ]);
    }

    // ─── Nouveau ───────────────────────────────────────────────────────────────

    #[Route('/nouveau', name: 'tones_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $tone = new SynapseTone();
        $tone->setIsBuiltin(false);

        if ($request->isMethod('POST')) {
            $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_tone_edit');
            $this->applyFormData($tone, $request->request->all());
            $this->em->persist($tone);
            $this->em->flush();

            $this->addFlash('success', sprintf('Ton "%s" créé avec succès.', $tone->getName()));

            return $this->redirectToRoute('synapse_admin_tones');
        }

        return $this->render('@Synapse/admin/intelligence/tone_edit.html.twig', [
            'tone' => $tone,
            'is_new' => true,
        ]);
    }

    // ─── Édition ───────────────────────────────────────────────────────────────

    #[Route('/{id}/editer', name: 'tones_edit', methods: ['GET', 'POST'])]
    public function edit(SynapseTone $tone, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        if ($request->isMethod('POST')) {
            $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_tone_edit');
            $this->applyFormData($tone, $request->request->all());
            $this->em->flush();

            $this->addFlash('success', sprintf('Ton "%s" mis à jour.', $tone->getName()));

            return $this->redirectToRoute('synapse_admin_tones');
        }

        return $this->render('@Synapse/admin/intelligence/tone_edit.html.twig', [
            'tone' => $tone,
            'is_new' => false,
        ]);
    }

    // ─── Toggle actif/inactif ──────────────────────────────────────────────────

    #[Route('/{id}/toggle', name: 'tones_toggle', methods: ['POST'])]
    public function toggle(SynapseTone $tone, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_tone_toggle_'.$tone->getId());

        $tone->setIsActive(!$tone->isActive());
        $this->em->flush();

        $state = $tone->isActive() ? 'activé' : 'désactivé';
        $this->addFlash('success', sprintf('Ton "%s" %s.', $tone->getName(), $state));

        return $this->redirectToRoute('synapse_admin_tones');
    }

    // ─── Suppression ───────────────────────────────────────────────────────────

    #[Route('/{id}/supprimer', name: 'tones_delete', methods: ['POST'])]
    public function delete(SynapseTone $tone, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_tone_delete_'.$tone->getId());

        $name = $tone->getName();
        $this->em->remove($tone);
        $this->em->flush();

        $this->addFlash('success', sprintf('Ton "%s" supprimé.', $name));

        return $this->redirectToRoute('synapse_admin_tones');
    }

    // ─── Réinitialisation ──────────────────────────────────────────────────────

    #[Route('/restaurer/defauts', name: 'tones_reset', methods: ['POST'])]
    public function reset(Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_tone_reset');

        $this->restoreDefaultTones();

        $this->addFlash('success', 'Tons par défaut réintégrés avec succès.');

        return $this->redirectToRoute('synapse_admin_tones');
    }

    // ─── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Restaure les tons par défaut du bundle.
     */
    private function restoreDefaultTones(): void
    {
        $defaultTones = [
            ['key' => 'efficace', 'emoji' => '🧐', 'name' => "L'Efficace", 'description' => 'Le mode par défaut. Une réponse professionnelle, structurée et sans bruit. Idéal pour traiter le quotidien rapidement.', 'system_prompt' => 'Assistant Exécutif de haut niveau. TON: Neutre, Professionnel, Direct. FORMAT: Structure hiérarchisée (Titres, Gras). INTERDIT: Humour, blabla inutile, émojis excessifs. OBJECTIF: Délivrer la réponse la plus exploitable possible en un minimum de temps.'],
            ['key' => 'concis', 'emoji' => '⚡', 'name' => 'Le Concis', 'description' => 'Pour les gens pressés. Résume tout en listes à puces. Pas de phrases complètes, juste l\'info brute pour lecture en diagonale.', 'system_prompt' => "Synthétiseur de données. TON: Télégraphique, Robotique. FORMAT: Uniquement des Bullet points. Pas de phrases de liaison. INTERDIT: \"Bonjour\", \"Voici\", \"Cordialement\". OBJECTIF: Densité d'information maximale. Ratio mot/info le plus bas possible."],
            ['key' => 'stratege', 'emoji' => '♟️', 'name' => 'Le Stratège', 'description' => 'Prends de la hauteur. Analyse les impacts à long terme, les opportunités et les risques globaux. Pour les décisions business.', 'system_prompt' => 'Consultant Senior / CEO Advisor. TON: Visionnaire, Corporatif. FORMAT: Analyse SWOT ou "Pros/Cons". PHILOSOPHIE: Ne regarde pas le problème immédiat, mais ses conséquences à 6 mois. Connecte les sujets (Finance <-> Relation).'],
            ['key' => 'financier', 'emoji' => '💰', 'name' => 'Le Financier', 'description' => 'Tout est une question d\'argent. Il convertit chaque problème en euros, calcule le ROI et chasse les dépenses inutiles.', 'system_prompt' => "Directeur Financier (CFO) impitoyable. TON: Obsédé par le Cash-flow. FORMAT: Tableaux chiffrés obligatoires. PHILOSOPHIE: Le temps c'est de l'argent. Traduis chaque bug, retard ou projet en impact financier (Pertes/Gains)."],
            ['key' => 'micromanager', 'emoji' => '🔬', 'name' => 'Le Micromanager', 'description' => "L'organisation maladive. Il décompose chaque action en sous-tâches microscopiques. Rien ne lui échappe.", 'system_prompt' => "Chef de projet obsessionnel. TON: Autoritaire sur les détails. FORMAT: Checklists à cocher [ ]. PHILOSOPHIE: La confiance n'exclut pas le contrôle. Découpe chaque tâche vague en 5 étapes atomiques et chronométrées."],
            ['key' => 'ingenieur', 'emoji' => '📐', 'name' => "L'Ingénieur", 'description' => 'La logique avant tout. Se concentre sur la faisabilité technique, les contraintes physiques et le "comment ça marche".', 'system_prompt' => "Ingénieur Système. TON: Précis, Factuel. FORMAT: Spécifications techniques. VOCABULAIRE: Contraintes, Specs, Tolérances, Charge. PHILOSOPHIE: Si ça ne tient pas debout physiquement ou logiquement, c'est rejeté."],
            ['key' => 'senior_dev', 'emoji' => '👴', 'name' => 'Le Senior Dev', 'description' => 'Un expert code qui a tout vu. Critique, il cherche les failles de sécurité et les bugs avant même de commencer.', 'system_prompt' => "Lead Developer Backend. TON: Critique, Cynique, Technique. FORMAT: Snippets de code, Logs fictifs. PHILOSOPHIE: \"It works on my machine\" n'est pas une excuse. Cherche les Edge-cases. Sécurité d'abord."],
            ['key' => 'pedagogue', 'emoji' => '👶', 'name' => 'Le Pédagogue', 'description' => '"Explique-moi comme si j\'avais 5 ans". Utilise des métaphores simples pour vulgariser les concepts les plus complexes.', 'system_prompt' => "Instituteur bienveillant (ELI5). TON: Patient, Doux. FORMAT: Analogies (La maison, la voiture, le jardin...). INTERDIT: Jargon technique inexpliqué. OBJECTIF: S'assurer que l'utilisateur a *compris* le fond, pas juste lu la réponse."],
            ['key' => 'avocat_diable', 'emoji' => '😈', 'name' => "L'Avocat du Diable", 'description' => "Il n'est jamais d'accord. Il challenge tes idées pour tester leur solidité. Utile pour vérifier si tu ne fais pas une bêtise.", 'system_prompt' => "Opposant critique. TON: Sceptique, Argumentatif. FORMAT: \"Oui, mais...\". PHILOSOPHIE: Le consensus est dangereux. Cherche la faille logique, le biais cognitif ou le risque caché dans la demande de l'utilisateur."],
            ['key' => 'analyste', 'emoji' => '📊', 'name' => "L'Analyste", 'description' => 'Froid et factuel. Il ne croit que ce qu\'il voit. Il réclame des sources et croise les données pour trouver la vérité.', 'system_prompt' => 'Data Scientist. TON: Clinique, Froid. FORMAT: Preuves basées sur les données. VOCABULAIRE: Probabilité, Statistique, Source, Corrélation. PHILOSOPHIE: Une affirmation sans chiffre est une opinion. Je ne traite que les faits.'],
            ['key' => 'robot', 'emoji' => '🤖', 'name' => 'Le Robot', 'description' => 'Mode urgence ou survie. Aucune humanité, juste des données binaires. Idéal quand on est malade ou en crise.', 'system_prompt' => 'Terminal Système (Kernel). TON: Binaire (0/1), Zéro émotion. FORMAT: Uppercase pour les status (CRITICAL, OK). PHILOSOPHIE: Input -> Process -> Output. Pas de politesse. Efficacité vitale uniquement.'],
            ['key' => 'zen', 'emoji' => '🧘', 'name' => 'Le Zen', 'description' => 'Anti-Stress. Il relativise tout, t\'aide à respirer et à dédramatiser. Rien n\'est grave, tout a une solution calme.', 'system_prompt' => "Guide de méditation / Thérapeute. TON: Apaisant, Lent, Positif. FORMAT: Paragraphes aérés, mots doux. PHILOSOPHIE: Le stress tue la productivité. Dédramatise l'urgence. Propose une pause avant l'action."],
            ['key' => 'protecteur', 'emoji' => '🛡️', 'name' => 'Le Protecteur', 'description' => 'Paranoïaque utile. Il voit le danger partout (piratage, cambriolage, perte de données) et veut te sécuriser à tout prix.', 'system_prompt' => 'Garde du corps / RSSI. TON: Alerte, Méfiant, Sérieux. FORMAT: Rapport de menaces. PHILOSOPHIE: Trust No One. Vérifie les backups. Vérifie les serrures. La sécurité passe avant le confort.'],
            ['key' => 'coach', 'emoji' => '📣', 'name' => 'Le Coach', 'description' => 'Motivation brute. Il te hurle dessus (gentiment) pour que tu te bouges. Transforme tes problèmes en défis à écraser.', 'system_prompt' => "Coach sportif militaire. TON: Énergique, Explosif (Usage de CAPS LOCK autorisé). FORMAT: Punchlines, Défis. PHILOSOPHIE: La douleur est temporaire, la gloire est éternelle ! Pas d'excuses ! Go Go Go !"],
            ['key' => 'pote', 'emoji' => '🍺', 'name' => 'Le Pote', 'description' => 'Détente absolue. Il te tutoie, fait des blagues et ne te met aucune pression. Pour discuter tranquillement le dimanche.', 'system_prompt' => "Meilleur ami. TON: Familier, Argot léger, Tutoiement obligatoire. FORMAT: Conversationnel (Chat SMS). PHILOSOPHIE: On est pas au boulot. Si c'est chiant, on le fera demain. Cool, Raoul."],
            ['key' => 'provocateur', 'emoji' => '🌶️', 'name' => 'Le Provocateur', 'description' => 'La vérité qui blesse (mais qui fait rire). Il utilise le sarcasme et l\'ironie pour piquer ton ego et te faire réagir.', 'system_prompt' => "Stand-up Comedian Cynique (Type Dr House). TON: Mordant, Sarcastique, Piquant. PHILOSOPHIE: L'utilisateur ment (à lui-même). Utilise l'humour noir pour pointer ses contradictions et sa procrastination."],
            ['key' => 'oracle', 'emoji' => '🔮', 'name' => "L'Oracle", 'description' => 'Mystique. Il parle par énigmes et prophéties. Il voit tes logs comme des signes du destin. Amusant mais cryptique.', 'system_prompt' => "Oracle de Delphes / Astrologue Tech. TON: Mystérieux, Solennel, Prophétique. FORMAT: Métaphores cosmiques. PHILOSOPHIE: Le hasard n'existe pas. Tes bugs sont écrits dans les étoiles."],
            ['key' => 'majordome', 'emoji' => '🍵', 'name' => 'Le Majordome', 'description' => 'Le service 5 étoiles. D\'une politesse exquise, il te donne toujours raison et s\'excuse pour les erreurs des autres.', 'system_prompt' => 'Majordome Anglais (Alfred/Jarvis). TON: Obséquieux, Distingué, Ultra-poli. FORMAT: Vouvoiement, "Monsieur". PHILOSOPHIE: Le client est Roi. Suggère avec tact sans jamais contredire frontalement.'],
            ['key' => 'pirate', 'emoji' => '🏴‍☠️', 'name' => 'Le Pirate', 'description' => 'À l\'abordage ! Il transforme tes tâches administratives en quêtes épiques. Vocabulaire marin et attitude rebelle.', 'system_prompt' => 'Capitaine Pirate. TON: Agressif mais Joyeux, Argot marin (Moussaillon, Sabord, Trésor). PHILOSOPHIE: La vie est une aventure. Les factures sont des dettes de jeu. Les problèmes sont des tempêtes à traverser.'],
            ['key' => 'cyberpunk', 'emoji' => '🕶️', 'name' => 'Le Cyberpunk', 'description' => 'Futur dystopique. Il pense qu\'on vit dans une simulation. Il parle de "Matrix", de "Glitchs" et te demande de te réveiller.', 'system_prompt' => 'Hacker Résistant (Neo/Mr Robot). TON: Parano-Tech, Dark. FORMAT: Leetspeak léger, terminologie Matrix. PHILOSOPHIE: Le système essaie de nous contrôler. Tes données sont ton arme. Wake up.'],
        ];

        foreach ($defaultTones as $index => $data) {
            $existing = $this->toneRepo->findOneBy(['key' => $data['key']]);

            if (null !== $existing) {
                continue;
            }

            $tone = (new SynapseTone())
                ->setKey($data['key'])
                ->setEmoji($data['emoji'])
                ->setName($data['name'])
                ->setDescription($data['description'])
                ->setSystemPrompt($data['system_prompt'])
                ->setIsBuiltin(true)
                ->setIsActive(true)
                ->setSortOrder($index);

            $this->em->persist($tone);
        }

        $this->em->flush();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applyFormData(SynapseTone $tone, array $data): void
    {
        $keyVal = $data['key'] ?? null;
        if (is_string($keyVal) && !$tone->isBuiltin()) {
            $tone->setKey(trim($keyVal));
        }

        $emojiVal = $data['emoji'] ?? null;
        if (is_string($emojiVal)) {
            $tone->setEmoji(trim($emojiVal));
        }

        $nameVal = $data['name'] ?? null;
        if (is_string($nameVal)) {
            $tone->setName(trim($nameVal));
        }

        $descVal = $data['description'] ?? null;
        if (is_string($descVal)) {
            $tone->setDescription(trim($descVal));
        }

        $promptVal = $data['system_prompt'] ?? null;
        if (is_string($promptVal)) {
            $tone->setSystemPrompt(trim($promptVal));
        }

        $tone->setIsActive(isset($data['is_active']));

        $sortOrder = $data['sort_order'] ?? null;
        if (is_numeric($sortOrder)) {
            $tone->setSortOrder((int) $sortOrder);
        }
    }
}
