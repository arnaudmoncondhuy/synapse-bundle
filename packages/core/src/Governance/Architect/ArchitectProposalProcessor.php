<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Governance\Architect;

use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\WorkflowDefinitionValidator;
use ArnaudMoncondhuy\SynapseCore\Governance\PromptVersionRecorder;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflow;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseAgentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Applique les propositions structurées de l'{@see ArchitectAgent} — Phase 11.
 *
 * Ce service est le pont entre la sortie JSON de l'architecte et les entités
 * Doctrine. Chaque proposition passe par le pipeline HITL existant :
 *
 *   - **Création d'agent** : l'agent est créé **inactif** avec une version
 *     de prompt **en pending** (Garde-fou #3). Le LLM-as-Judge (Garde-fou #2)
 *     est invoqué automatiquement par le {@see PromptVersionRecorder}.
 *     L'admin doit approuver pour que l'agent devienne opérationnel.
 *
 *   - **Amélioration de prompt** : une version pending est créée via
 *     `PromptVersionRecorder::snapshot(pending: true)`. Le prompt live de
 *     l'agent n'est pas modifié tant que la version n'est pas approuvée.
 *
 *   - **Création de workflow** : le workflow est créé **inactif**. L'admin
 *     doit le passer actif après revue.
 *
 * ## Audit trail
 *
 * Toutes les entités créées utilisent la convention `changedBy` = `agent:architect`
 * pour tracer l'origine dans l'historique.
 */
class ArchitectProposalProcessor
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SynapseAgentRepository $agentRepository,
        private readonly PromptVersionRecorder $promptVersionRecorder,
        private readonly WorkflowDefinitionValidator $definitionValidator,
        #[Autowire('%synapse.ephemeral.retention_days%')]
        private readonly int $retentionDays = 7,
    ) {
    }

    /**
     * Calcule une date de rétention = maintenant + retention_days.
     */
    private function computeRetentionUntil(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->modify(sprintf('+%d days', $this->retentionDays));
    }

    /**
     * Dispatche une proposition vers le handler approprié selon `_action`.
     *
     * @param array<string, mixed> $proposal sortie structurée de l'ArchitectAgent
     *
     * @throws \InvalidArgumentException si la proposition est invalide
     *
     * @return array{type: string, entity: SynapseAgent|SynapseWorkflow, message: string}
     */
    public function process(array $proposal, string $changedBy = 'agent:architect'): array
    {
        $action = $proposal['_action'] ?? null;
        if (!is_string($action)) {
            throw new \InvalidArgumentException('Proposition invalide : clé "_action" manquante.');
        }

        return match ($action) {
            'create_agent' => $this->processCreateAgent($proposal, $changedBy),
            'improve_prompt' => $this->processImprovePrompt($proposal, $changedBy),
            'create_workflow' => $this->processCreateWorkflow($proposal, $changedBy),
            default => throw new \InvalidArgumentException(sprintf('Action de proposition inconnue : "%s".', $action)),
        };
    }

    /**
     * Crée un nouvel agent inactif + version prompt en pending.
     *
     * @param array<string, mixed> $proposal
     *
     * @return array{type: string, entity: SynapseAgent, message: string}
     */
    private function processCreateAgent(array $proposal, string $changedBy): array
    {
        $key = $this->requireString($proposal, 'key', 'Clé d\'agent');
        $name = $this->requireString($proposal, 'name', 'Nom d\'agent');
        $systemPrompt = $this->requireString($proposal, 'system_prompt', 'System prompt');

        $existing = $this->agentRepository->findByKey($key);
        if (null !== $existing) {
            throw new \InvalidArgumentException(sprintf('Un agent avec la clé "%s" existe déjà.', $key));
        }

        $agent = new SynapseAgent();
        $agent->setKey($key);
        $agent->setName($name);
        $agent->setEmoji(is_string($proposal['emoji'] ?? null) ? $proposal['emoji'] : '🤖');
        $agent->setDescription(is_string($proposal['description'] ?? null) ? $proposal['description'] : '');
        $agent->setSystemPrompt($systemPrompt);
        $agent->setIsBuiltin(false);
        $agent->setIsActive(false); // Inactif — attend approbation admin
        // Chantier E phase 2 : appliquer les allowed_tools générés par
        // l'ArchitectAgent si renseignés. Liste vide = accès à tous les
        // outils (sémantique existante sur SynapseAgent::hasToolRestrictions).
        if (isset($proposal['allowed_tools']) && is_array($proposal['allowed_tools'])) {
            /** @var list<string> $tools */
            $tools = array_values(array_filter($proposal['allowed_tools'], 'is_string'));
            $agent->setAllowedToolNames($tools);
        }
        // Marqué éphémère : visible dans la section « Propositions » de l'admin,
        // promouvable via l'action dédiée, sinon GC au bout de retention_days.
        $agent->setIsEphemeral(true);
        $agent->setRetentionUntil($this->computeRetentionUntil());

        $this->entityManager->persist($agent);

        // Flush immédiat pour que l'agent ait un ID avant que le recorder ne
        // query des versions précédentes via cet agent. Sans ça, Doctrine lève
        // "Binding entities to query parameters only allowed for entities that
        // have an identifier". Le snapshot qui suit est dans la même
        // transaction logique — pas de risque d'état incohérent.
        $this->entityManager->flush();

        // Snapshot le prompt en pending (HITL) — déclenche le LLM-as-Judge
        $reason = is_string($proposal['reasoning'] ?? null) ? $proposal['reasoning'] : null;
        $this->promptVersionRecorder->snapshot(
            agent: $agent,
            newPrompt: $systemPrompt,
            changedBy: $changedBy,
            reason: $reason,
            flush: true,
            pending: true,
        );

        return [
            'type' => 'create_agent',
            'entity' => $agent,
            'message' => sprintf('Agent "%s" créé (inactif, prompt en attente de validation).', $name),
        ];
    }

    /**
     * Crée une version pending pour un agent existant.
     *
     * @param array<string, mixed> $proposal
     *
     * @return array{type: string, entity: SynapseAgent, message: string}
     */
    private function processImprovePrompt(array $proposal, string $changedBy): array
    {
        $newPrompt = $this->requireString($proposal, 'new_system_prompt', 'Nouveau prompt');

        // Retrouver l'agent — le _agent_key est ajouté par le CLI/caller
        $agentKey = $proposal['_agent_key'] ?? null;
        if (!is_string($agentKey) || '' === $agentKey) {
            throw new \InvalidArgumentException('Clé "_agent_key" manquante dans la proposition — le caller doit la renseigner.');
        }

        $agent = $this->agentRepository->findByKey($agentKey);
        if (null === $agent) {
            throw new \InvalidArgumentException(sprintf('Agent "%s" introuvable.', $agentKey));
        }

        $reason = is_string($proposal['changes_summary'] ?? null) ? $proposal['changes_summary'] : null;
        $version = $this->promptVersionRecorder->snapshot(
            agent: $agent,
            newPrompt: $newPrompt,
            changedBy: $changedBy,
            reason: $reason,
            flush: true,
            pending: true,
        );

        $message = null !== $version
            ? sprintf('Proposition de prompt soumise pour l\'agent "%s" (version #%s, en attente de validation).', $agent->getName(), (string) $version->getId())
            : sprintf('Prompt identique à la version actuelle de l\'agent "%s" — aucune modification.', $agent->getName());

        return [
            'type' => 'improve_prompt',
            'entity' => $agent,
            'message' => $message,
        ];
    }

    /**
     * Crée un nouveau workflow inactif.
     *
     * @param array<string, mixed> $proposal
     *
     * @return array{type: string, entity: SynapseWorkflow, message: string}
     */
    private function processCreateWorkflow(array $proposal, string $changedBy): array
    {
        $key = $this->requireString($proposal, 'key', 'Clé de workflow');
        $name = $this->requireString($proposal, 'name', 'Nom de workflow');

        $steps = $proposal['steps'] ?? null;
        if (!is_array($steps) || [] === $steps) {
            throw new \InvalidArgumentException('Le workflow doit contenir au moins une étape.');
        }

        // Chantier K2 : normalise les steps du format Architect (avec wrapper
        // `config`) vers le format flat historique que le builder admin sait
        // lire/écrire. Le schema LLM utilise `config` parce que ça marche
        // beaucoup mieux qu'un schéma flat avec 10 champs ambigus, mais en
        // DB on stocke toujours en flat pour garder la compat avec tout ce
        // qui existait déjà (MCP, builder Stimulus, tests).
        $steps = $this->flattenConfigWrapper($steps);

        $definition = [
            'version' => 1,
            'description' => is_string($proposal['description'] ?? null) ? $proposal['description'] : '',
            'steps' => $steps,
        ];

        // Chantier J partie 2 : validation **warning-only** pour les workflows
        // proposés par l'Architect. L'ancienne politique (rejet strict avant
        // persistance) se heurtait à la réalité : le LLM Gemini Flash Lite
        // oublie régulièrement des champs obligatoires sur les types
        // conditional/parallel/loop. Plutôt que de bloquer le user, on
        // persiste l'éphémère tel quel (inactif + non-promu) et on log un
        // warning. Le user ouvre le workflow dans le builder admin qui
        // affiche chaque step avec ses champs éditables, corrige, puis
        // promeut. La validation stricte reste en place côté admin au save
        // (cf. WorkflowController::applyFormData).
        $validationError = $this->definitionValidator->validate($definition);
        if (null !== $validationError) {
            error_log(sprintf(
                '[ArchitectProposalProcessor] Workflow proposé par l\'architect contient des erreurs de validation (persisté comme éphémère brouillon) : %s',
                $validationError,
            ));
        }

        $workflow = new SynapseWorkflow();
        $workflow->setWorkflowKey($key);
        $workflow->setName($name);
        $workflow->setDescription(is_string($proposal['description'] ?? null) ? $proposal['description'] : null);
        $workflow->setDefinition($definition);
        $workflow->setIsBuiltin(false);
        $workflow->setIsActive(false); // Inactif — attend activation admin
        // Proposition éphémère : visible dans la section « Workflows éphémères
        // récents » de l'admin, promouvable, sinon GC au bout de retention_days.
        $workflow->setIsEphemeral(true);
        $workflow->setRetentionUntil($this->computeRetentionUntil());

        $this->entityManager->persist($workflow);
        $this->entityManager->flush();

        return [
            'type' => 'create_workflow',
            'entity' => $workflow,
            'message' => sprintf('Workflow "%s" créé (inactif, en attente d\'activation par un admin).', $name),
        ];
    }

    /**
     * Extrait une chaîne requise depuis la proposition.
     *
     * @param array<string, mixed> $proposal
     */
    private function requireString(array $proposal, string $key, string $label): string
    {
        $value = $proposal[$key] ?? null;
        if (!is_string($value) || '' === trim($value)) {
            throw new \InvalidArgumentException(sprintf('%s manquant ou vide dans la proposition (clé : "%s").', $label, $key));
        }

        return trim($value);
    }

    /**
     * Chantier K2 : normalise les steps du format Architect 2026-04-11+ vers
     * le format flat historique.
     *
     * L'Architect génère des steps de la forme :
     *     { name, type, config: { agent_name, condition, branches, ... } }
     *
     * On remonte les clés de `config` au niveau du step pour obtenir :
     *     { name, type, agent_name, condition, branches, ... }
     *
     * Raison : le format flat est celui que le builder admin Stimulus, le
     * MCP, les tests unitaires et les workflows écrits manuellement utilisent
     * depuis le début. Garder une seule représentation en DB simplifie
     * drastiquement la cohérence. Le format `config` n'existe que le temps
     * du transit LLM → DB.
     *
     * La normalisation est **récursive** pour gérer les cas :
     * - `parallel.config.branches` (chaque branche est un step complet)
     * - `loop.config.step` (le template est un step complet)
     *
     * @param array<int, mixed> $steps
     *
     * @return array<int, array<string, mixed>>
     */
    private function flattenConfigWrapper(array $steps): array
    {
        $normalized = [];
        foreach ($steps as $step) {
            if (!is_array($step)) {
                continue;
            }
            $normalized[] = $this->flattenStep($step);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $step
     *
     * @return array<string, mixed>
     */
    private function flattenStep(array $step): array
    {
        $config = $step['config'] ?? null;
        if (is_array($config)) {
            // Remonte les clés de config au niveau du step (config écrase le flat
            // si conflit — c'est ce que le LLM a généré qui fait foi).
            foreach ($config as $key => $value) {
                $step[$key] = $value;
            }
            unset($step['config']);
        }

        // Récursion pour parallel.branches (liste de sous-steps)
        if (isset($step['branches']) && is_array($step['branches'])) {
            $step['branches'] = array_map(
                fn ($b) => is_array($b) ? $this->flattenStep($b) : $b,
                $step['branches'],
            );
        }

        // Récursion pour loop.step (un seul sous-step template)
        if (isset($step['step']) && is_array($step['step'])) {
            $step['step'] = $this->flattenStep($step['step']);
        }

        return $step;
    }
}
