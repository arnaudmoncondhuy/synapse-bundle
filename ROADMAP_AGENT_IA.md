# ROADMAP: Transformation Missions → Agents IA Autonomes

**Document de référence pour la transformation du synapse-bundle**
- **Dernière mise à jour**: 2026-03-07
- **Status**: 🔄 En planification (Phase 1)
- **Propriétaire**: Équipe Dev

---

## 📋 Vue d'Ensemble

Le synapse-bundle dispose déjà des briques fondamentales pour supporter des Agents IA autonomes. Cette roadmap détaille :
1. **Ce qui existe** et est prêt
2. **Ce qui manque** pour être "Agent Ready"
3. **Le plan d'implémentation** par phase
4. **Les fichiers** concernés
5. **Les défis** techniques à résoudre

### Définition: Agent IA vs Mission
```
MISSION (actuellement)      →  AGENT IA (cible)
├─ Prompt Système              ├─ Identité/Rôle
├─ Preset technique            ├─ Capacités (outils)
├─ Accès à TOUS les outils     ├─ Accès contrôlé aux outils
└─ Exécution linéaire          └─ Réflexion multi-tour autonome
```

---

## ✅ État Actuel - Ce qui est Prêt

### 1. Boucle de Réflexion Multi-tour ✅
**Fichier**: `src/Core/ChatService.php`
- **Capacité**: Supporte jusqu'à 5 tours de conversation (MAX_TURNS = 5)
- **Fonctionnement**:
  - Tour 1: IA reçoit prompt + outils, génère réponse
  - Si `tool_calls` détectés:
    - Exécute les outils (AiToolInterface)
    - Retour résultats à l'IA
    - Tour 2+: IA continue réflexion
  - Fin: Aucun `tool_calls` ou MAX_TURNS atteint
- **Provider agnostique**: ✅ Fonctionne avec Gemini, OpenAI, OVH
- **État**: Production-ready

### 2. Système d'Outils ✅
**Fichier**: `src/Contract/AiToolInterface.php`
- **Concepts**:
  - Chaque outil = implémentation de `AiToolInterface`
  - Déclaration: `getName()`, `getDescription()`, `getParameters()`
  - Exécution: `execute(array $params): string`
- **Outils existants**:
  - `SearchTool` - Recherche dans la base
  - `WebSearchTool` - Recherche web
  - `CreativeContentTool` - Génération créative
  - `ProgrammingTool` - Assistance code
  - D'autres...
- **Enregistrement**: Tous les outils sont enregistrés globalement dans le DI
- **État**: Robuste et extensible

### 3. Configuration Flexible ✅
**Fichier**: `src/Storage/Entity/SynapseMission.php`
- **Surcharges par Mission**:
  - `model`: Peut changer le modèle LLM
  - `temperature`: Contrôle de la créativité
  - `systemInstruction`: Prompt unique
  - `maxTokens`: Limite de génération
- **État**: Fonctionnel

### 4. Architecture Modulaire ✅
**Fichiers clés**:
- `PromptBuilder` - Construction des prompts
- `ContextBuilderSubscriber` - Contexte dynamique
- `EventSystem` - SynapseChunkReceivedEvent, etc.
- **Avantage**: Extensible sans modifier le cœur

---

## 🚧 Ce qu'il Manque - Blocages Identifiés

### A. Liaison Dynamique Missions ↔ Outils ⚠️ CRITIQUE

**Problème actuellement**:
- Les outils sont enregistrés **globalement**
- Toute Mission a accès à **TOUS les outils**
- Pas de contrôle d'accès granulaire

**Cas d'usage**:
```
Agent "Support Technique" → outils: SearchTool, ProgrammingTool
Agent "Admin Suppression" → outils: DeleteUserTool (uniquement)
Agent "Marketing" → outils: ContentGenerationTool
```

**Implémentation requise**:

1. **Nouvelle propriété dans SynapseMission** (src/Storage/Entity/SynapseMission.php):
```php
/**
 * @ORM\ManyToMany(targetEntity="AiTool")
 * @ORM\JoinTable(name="synapse_mission_ai_tools")
 */
private Collection $allowedTools;

public function getAllowedTools(): Collection { return $this->allowedTools; }
public function addAllowedTool(AiTool $tool): self { ... }
public function removeAllowedTool(AiTool $tool): self { ... }
```

2. **Nouvelle entité AiTool** (src/Storage/Entity/AiTool.php):
```php
/**
 * @ORM\Entity
 * @ORM\Table(name="synapse_ai_tool")
 */
class AiTool
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private int $id;

    /** @ORM\Column(type="string", length=255, unique=true) */
    private string $serviceId;  // e.g., "app.tool.search"

    /** @ORM\Column(type="string", length=255) */
    private string $name;  // e.g., "Search Tool"

    /** @ORM\Column(type="text") */
    private string $description;

    /** @ORM\Column(type="boolean") */
    private bool $isActive = true;
}
```

3. **ChatService**: Modifier `getAvailableTools()` pour filtrer selon la Mission:
```php
private function getAvailableTools(SynapseMission $mission): array
{
    $allowedToolIds = $mission->getAllowedTools()
        ->map(fn(AiTool $t) => $t->getServiceId())
        ->toArray();

    return array_filter(
        $this->allRegisteredTools,
        fn($tool) => in_array($tool->getServiceId(), $allowedToolIds)
    );
}
```

**Fichiers à créer/modifier**:
- [ ] `src/Storage/Entity/AiTool.php` (NEW)
- [ ] `src/Storage/Repository/AiToolRepository.php` (NEW)
- [ ] Migration: `migrations/VersionXXX_CreateAiToolTable.php` (NEW)
- [ ] Migration: `migrations/VersionXXX_AddMissionAiToolsRelation.php` (NEW)
- [ ] `src/Core/ChatService.php` (MODIFY - filterTools)
- [ ] `src/Admin/Controller/MissionsController.php` (MODIFY - UI pour cocher outils)
- [ ] `admin_v2/missions/edit.html.twig` (NEW form field)

**Effort estimé**: 2-3 jours

**Risques**:
- ⚠️ Backward compatibility: Missions existantes doivent hériter tous les outils par défaut
- ⚠️ Performance: Index sur synapse_mission_ai_tools.mission_id

---

### B. Outil "Interpréteur Python" 🔒 COMPLEXE

**Problème actuellement**:
- Pas d'outil pour exécuter du code dynamique
- Les agents complexes ne peuvent pas faire de maths, data-science, ou scripting

**Cas d'usage**:
```
Agent "Data Analyst" fait demande:
  "Analyse ces 100 données et trouve les tendances"
  → Outil Python exécute script d'analyse
  → Retour: DataFrame résumé au LLM
```

**Implémentation requise**:

1. **Création de PythonExecutorTool** (src/Shared/Tool/PythonExecutorTool.php):
```php
class PythonExecutorTool implements AiToolInterface
{
    private PythonSandboxService $sandbox;

    public function getName(): string { return "Python Executor"; }

    public function getDescription(): string
    {
        return "Exécute du code Python en sandbox. "
             . "Utile pour: maths, data-science, transformation de données.";
    }

    public function getParameters(): array
    {
        return [
            'code' => [
                'type' => 'string',
                'description' => 'Code Python à exécuter (max 5000 caractères)',
            ],
            'timeout' => [
                'type' => 'integer',
                'description' => 'Timeout en secondes (1-60, défaut: 10)',
            ],
        ];
    }

    public function execute(array $params): string
    {
        $code = $params['code'] ?? '';
        $timeout = $params['timeout'] ?? 10;

        // Validation
        if (strlen($code) > 5000) throw new \Exception("Code trop long");
        if ($timeout < 1 || $timeout > 60) throw new \Exception("Timeout invalide");

        // Exécution sandbox
        try {
            $result = $this->sandbox->execute($code, $timeout);
            return json_encode(['status' => 'success', 'output' => $result]);
        } catch (PythonTimeoutException $e) {
            return json_encode(['status' => 'timeout', 'error' => 'Exécution > '.$timeout.'s']);
        } catch (\Exception $e) {
            return json_encode(['status' => 'error', 'error' => $e->getMessage()]);
        }
    }
}
```

2. **Service: PythonSandboxService** (src/Infrastructure/Python/PythonSandboxService.php):
```php
class PythonSandboxService
{
    private string $containerName = 'synapse-python-runner';
    private string $timeout = 10;

    /**
     * Exécute du code Python dans un container Docker isolé
     */
    public function execute(string $code, int $timeout = 10): string
    {
        // Validation sécurité: blocklist de patterns dangereux
        $this->validateCode($code);

        // Préparation: wrapper du code
        $wrappedCode = $this->wrapCode($code);

        // Exécution Docker
        $result = $this->runInDocker($wrappedCode, $timeout);

        return $result;
    }

    private function validateCode(string $code): void
    {
        $blockedPatterns = [
            '/import\s+os\b/',       // Pas d'accès système
            '/subprocess/',           // Pas de sous-processus
            '/open\s*\(/',            // Pas d'accès filesystem
            '/exec\s*\(/',            // Pas d'exec
            '/eval\s*\(/',            // Pas d'eval
            '/__import__/',           // Pas d'imports dynamiques
        ];

        foreach ($blockedPatterns as $pattern) {
            if (preg_match($pattern, $code, $m)) {
                throw new \Exception("Pattern dangereux détecté: {$m[0]}");
            }
        }
    }

    private function wrapCode(string $code): string
    {
        return <<<PYTHON
import sys
import json

try:
    # Exécution du code utilisateur
    {$code}

    # Capture de la sortie
    print(json.dumps({"status": "success", "output": locals()}))
except Exception as e:
    print(json.dumps({"status": "error", "error": str(e)}))
PYTHON;
    }

    private function runInDocker(string $code, int $timeout): string
    {
        $cmd = sprintf(
            'docker run --rm --cpus=0.5 --memory=256m --timeout %d %s python - <<\'EOF\'%s%sEOF',
            $timeout,
            $this->containerName,
            PHP_EOL,
            $code
        );

        // Exécution avec timeout
        $process = new Process(explode(' ', $cmd), null, null, null, $timeout);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \Exception("Python execution failed: " . $process->getErrorOutput());
        }

        return $process->getOutput();
    }
}
```

3. **Configuration DI** (src/Infrastructure/Resources/config/core.yaml):
```yaml
services:
  app.sandbox.python:
    class: ArnaudMoncondhuy\SynapseCore\Infrastructure\Python\PythonSandboxService
    arguments:
      - $containerName: 'synapse-python-runner'

  app.tool.python_executor:
    class: ArnaudMoncondhuy\SynapseCore\Shared\Tool\PythonExecutorTool
    arguments:
      - $sandbox: '@app.sandbox.python'
    tags:
      - { name: 'synapse.ai_tool' }
```

**Fichiers à créer**:
- [ ] `src/Shared/Tool/PythonExecutorTool.php` (NEW)
- [ ] `src/Infrastructure/Python/PythonSandboxService.php` (NEW)
- [ ] `src/Infrastructure/Python/Exception/PythonTimeoutException.php` (NEW)
- [ ] `docker/Dockerfile.python` (NEW - Image d'exécution)
- [ ] `config/core.yaml` (MODIFY - DI)

**Dockerfile.python** (minimal, production-safe):
```dockerfile
FROM python:3.11-slim
RUN pip install numpy pandas requests --no-cache-dir
RUN useradd -m -u 1000 runner
USER runner
WORKDIR /tmp
ENTRYPOINT ["python"]
```

**Effort estimé**: 5-7 jours (surtout la sécurité du sandbox)

**Risques**:
- 🔒 **CRITIQUE**: Injection de code - validation très stricte requise
- 🔒 Accès système - Docker constraint: `--cpus=0.5 --memory=256m`
- 🔒 Boucles infinies - Timeout strict (10s max)
- ⚠️ Dépendance Docker - Ne marche qu'avec Docker Compose
- ⚠️ Performance - Overhead de création container (500-1000ms)

**Mitigations de sécurité**:
```php
// 1. Whitelist au lieu de blacklist
// 2. Isolation Docker stricte
// 3. Timeout réseau + processus
// 4. Logs de tous les appels
// 5. Rate limiting par utilisateur/mission
```

---

### C. Mémoire à Long Terme / RAG 🧠 MOYEN

**Problème actuellement**:
- Les agents n'ont pas de "mémoire" entre conversations
- Pas de base de connaissance consultable

**Cas d'usage**:
```
Agent "Support Produit":
  Q: "Comment configurer l'authentification?"
  → Cherche dans base docs
  → Retourne documentation pertinente
  → Répond avec contexte enrichi
```

**Implémentation requise**:

1. **Nouvelle entité: KnowledgeBase** (src/Storage/Entity/KnowledgeBase.php):
```php
/**
 * @ORM\Entity
 * @ORM\Table(name="synapse_knowledge_base")
 */
class KnowledgeBase
{
    /** @ORM\Id @ORM\GeneratedValue @ORM\Column(type="integer") */
    private int $id;

    /** @ORM\Column(type="string", length=255) */
    private string $title;

    /** @ORM\Column(type="text") */
    private string $content;

    /** @ORM\Column(type="string", length=1024) */
    private string $embedding;  // Vector as JSON

    /** @ORM\Column(type="string", length=50) */
    private string $category;  // "docs", "faq", "product", etc.

    /** @ORM\ManyToOne(targetEntity="SynapseMission") */
    private ?SynapseMission $mission = null;  // null = global

    /** @ORM\Column(type="datetime_immutable") */
    private \DateTimeImmutable $createdAt;
}
```

2. **Outil de recherche** (src/Shared/Tool/KnowledgeSearchTool.php):
```php
class KnowledgeSearchTool implements AiToolInterface
{
    private EmbeddingService $embeddings;
    private KnowledgeBaseRepository $repo;

    public function execute(array $params): string
    {
        $query = $params['query'] ?? '';
        $limit = $params['limit'] ?? 5;

        // Génère embedding pour la query
        $queryEmbedding = $this->embeddings->embed($query);

        // Recherche vectorielle (cosine similarity)
        $results = $this->repo->findBySemanticSimilarity(
            $queryEmbedding,
            $limit
        );

        return json_encode([
            'results' => array_map(fn(KnowledgeBase $kb) => [
                'title' => $kb->getTitle(),
                'content' => substr($kb->getContent(), 0, 500),
                'category' => $kb->getCategory(),
                'relevance' => $kb->getScore(),  // 0-1
            ], $results)
        ]);
    }
}
```

3. **Intégration dans ContextBuilderSubscriber**:
```php
// Dans onPreBuildContext
if ($mission->hasKnowledgeBase()) {
    $similarDocs = $this->knowledgeSearchTool->search(
        $conversation->getLastMessage()->getContent(),
        limit: 3
    );

    $context['knowledge'] = $similarDocs;

    // Ajouter au prompt système
    $systemInstruction .= "\n\n## Base de connaissances pertinente:\n"
                       . json_encode($similarDocs);
}
```

**Fichiers à créer/modifier**:
- [ ] `src/Storage/Entity/KnowledgeBase.php` (NEW)
- [ ] `src/Storage/Repository/KnowledgeBaseRepository.php` (NEW)
- [ ] `src/Shared/Tool/KnowledgeSearchTool.php` (NEW)
- [ ] Migration: `migrations/VersionXXX_CreateKnowledgeBase.php` (NEW)
- [ ] `src/Core/ContextBuilderSubscriber.php` (MODIFY)

**Effort estimé**: 3-4 jours

**Risques**:
- ⚠️ Performance: Recherche vectorielle sur 10k+ docs = lent
- ⚠️ Qualité: Embeddings peuvent ne pas trouver pertinence exacte
- ⚠️ Maintenance: Mise à jour docs = recalcul embeddings

**Optimisation**:
- Indexation vectorielle (HNSW/Faiss)
- Cache des embeddings fréquents
- Pagination (top-3 résultats pour chaque query)

---

### D. Gestion des Permissions Utilisateur 🔐 IMPORTANT

**Problème actuellement**:
- Pas de contrôle qui peut accéder à quel Agent
- Pas de rate limiting par agent/utilisateur

**Implémentation requise**:

1. **Relation Mission ↔ User/Role**:
```php
// Dans SynapseMission
/**
 * @ORM\ManyToMany(targetEntity="Role")
 */
private Collection $allowedRoles;
```

2. **Middleware/Voter**:
```php
class MissionAccessVoter extends Voter
{
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        if ($subject instanceof SynapseMission && $attribute === 'USE_MISSION') {
            $user = $token->getUser();
            return $subject->getAllowedRoles()
                ->exists(fn($key, Role $role) => $user->hasRole($role));
        }
        return false;
    }
}
```

**Effort estimé**: 1-2 jours

---

## 🎯 Plan de Route Détaillé

### Phase 1: Fondations (Semaines 1-2)

**Objectif**: Rendre Missions contrôlables granulairementRank des tâches par valeur/effort:

#### 1.1 Liaison Missions ↔ Outils ⭐ CRITIQUE
- **Priorité**: 1 (blocker pour les phases suivantes)
- **Effort**: 2-3 jours
- **Dépendances**: Aucune
- **Actions**:
  1. Créer entité `AiTool`
  2. Ajouter relation `SynapseMission.allowedTools`
  3. Modifier `ChatService.getAvailableTools()`
  4. UI: Formulaire Mission avec checkboxes des outils
  5. Tests: Vérifier qu'un Agent n'accède qu'à ses outils autorisés
- **Définition de "fait"**:
  - ✅ Tests passent (9 tests minimum)
  - ✅ UI admin permet cocher/décocher outils
  - ✅ ChatService respecte la whitelist

#### 1.2 Outil Python Executor 🔒 OPTIONNEL (Phase 1.5)
- **Priorité**: 2 (après fondations)
- **Effort**: 5-7 jours
- **Dépendances**: 1.1
- **Actions**:
  1. Créer `PythonSandboxService` avec Docker
  2. Créer `PythonExecutorTool`
  3. Tests de sécurité: tentatives injection
  4. Performance: mesurer overhead
- **Définition de "fait"":
  - ✅ `code_analysis = "import numpy; np.mean([1,2,3])"` exécute sans risque
  - ✅ `__import__('os')` est bloqué
  - ✅ Timeout = 10s max
  - ✅ Tests de sécurité réussis

#### 1.3 Admin UI: Renommage Missions → Agents
- **Priorité**: 3 (cosmétique, mais important UX)
- **Effort**: 1 jour
- **Actions**:
  1. Remplacer "Missions" par "Agents" dans l'UI
  2. Mettre à jour labels, boutons, messages
  3. Docs: Mettre à jour glossaire
- **Fichiers touchés**: `admin_v2/missions/*`, contrôleurs

---

### Phase 2: Connaissance & Intelligence (Semaines 3-4)

#### 2.1 RAG / Knowledge Base
- **Priorité**: 1
- **Effort**: 3-4 jours
- **Dépendances**: 1.1
- **Actions**: (détail dans section C plus haut)

#### 2.2 Mémoire Court Terme (Conversation Memory)
- **Priorité**: 2
- **Effort**: 2 jours
- **Description**: Stocker contexte conversation pour multi-turns
```php
// Exemple: Agent se souvient des décisions précédentes
Agent: "Crée un utilisateur"
User: "Oui, crée John Doe"
Agent: [Se souvient que User a approuvé John Doe]
```

---

### Phase 3: Sécurité & Production (Semaines 5-6)

#### 3.1 Permissions Granulaires
- **Effort**: 1-2 jours
- **Actions**: Voter/ACL pour contrôler qui accède aux Agents

#### 3.2 Rate Limiting & Quotas
- **Effort**: 2 jours
- **Description**: Limiter appels par utilisateur/agent/jour
```php
max_calls_per_day: 1000
max_tokens_per_day: 100_000
```

#### 3.3 Audit & Logging
- **Effort**: 2 jours
- **Description**: Logger toutes les exécutions d'agents
```
[AGENT_EXEC] agent=support user=john.doe mission=tech_support
    tools_used=[search,python] tokens_used=2541 status=success
```

---

### Phase 4: Optimisations & Features (Semaines 7+)

#### 4.1 Agent Composition
- **Description**: Combiner plusieurs agents pour tâches complexes
```php
Agent "Orchestrator":
  ├─ Délègue à Agent "Analysis"
  ├─ Reçoit résultats
  ├─ Délègue à Agent "Report"
  └─ Fournit réponse finale
```

#### 4.2 Agent Versioning
- **Description**: Versions des agents avec rollback
```
Agent "Support" v1.0 (prod)
Agent "Support" v1.1 (staging) ← en test
```

#### 4.3 Custom Agents via Web UI
- **Description**: Interface pour créer agents sans code

---

## 📁 Structure de Fichiers Touchés

```
src/
├── Storage/
│   ├── Entity/
│   │   ├── SynapseMission.php        [MODIFY] + allowedTools
│   │   ├── AiTool.php               [NEW]
│   │   └── KnowledgeBase.php         [NEW]
│   └── Repository/
│       ├── AiToolRepository.php      [NEW]
│       └── KnowledgeBaseRepository.php [NEW]
│
├── Core/
│   ├── ChatService.php               [MODIFY] filterTools()
│   └── ContextBuilderSubscriber.php  [MODIFY] RAG integration
│
├── Shared/
│   ├── Tool/
│   │   ├── PythonExecutorTool.php    [NEW]
│   │   ├── KnowledgeSearchTool.php   [NEW]
│   │   └── [autres tools]
│   └── Enum/
│       └── ToolCategoryEnum.php      [NEW]
│
├── Infrastructure/
│   ├── Python/
│   │   ├── PythonSandboxService.php  [NEW]
│   │   └── Exception/
│   │       └── PythonTimeoutException.php [NEW]
│   │
│   └── Resources/config/
│       └── core.yaml                 [MODIFY] DI
│
└── Admin/
    ├── Controller/
    │   ├── MissionsController.php     [MODIFY] UI pour outils/perms
    │   └── AiToolsController.php      [NEW] CRUD tools
    │
    └── templates/
        └── missions/
            ├── edit.html.twig        [MODIFY] + tool checkboxes
            └── list.html.twig        [MODIFY] + rename

migrations/
├── VersionXXX_CreateAiToolTable.php           [NEW]
├── VersionXXX_AddMissionAiToolsRelation.php   [NEW]
└── VersionXXX_CreateKnowledgeBase.php         [NEW]

docker/
└── Dockerfile.python                 [NEW] Python sandbox
```

---

## 🔒 Considérations de Sécurité Critique

### 1. Injection de Code Python
- ❌ Ne JAMAIS utiliser `eval()` ou `exec()` sur input utilisateur
- ✅ Whitelist regex pour patterns approuvés
- ✅ Docker isolation (--read-only filesystem)
- ✅ Timeout strict (10s)

### 2. Outils Dangereux
- Certains outils (DeleteUserTool) doivent être 🔒 restreints
- Audit trails obligatoires
- Double confirmation (2FA) pour actions destructives

### 3. Token Limits & Costs
- ⚠️ ChatGPT/Claude: tokens = coûts réels
- Implémenter quota par mission
- Monitoring des dépassements

### 4. Data Privacy (RGPD)
- Logs d'exécution ne doivent pas contenir PII
- Soft-delete pour audit trail

---

## 📊 Métriques de Succès

### Phase 1 (Fondations)
```
✅ Liaison Missions ↔ Outils:
  - 100% des missions peuvent choisir leurs outils
  - 0 accès non-autorisés en production

✅ Tests:
  - 9+ tests unitaires (ChatService, AiTool, Permission)
  - 1+ test d'intégration (mission complète)
```

### Phase 2 (Intelligence)
```
✅ RAG:
  - 95%+ pertinence sur requêtes standard
  - <500ms latence de recherche

✅ Python Executor:
  - Exécute code données analytiques sans risque
  - 0 injections réussies
```

### Phase 3 (Production)
```
✅ Sécurité:
  - 100% des actions auditées
  - Rate limiting actif et testé

✅ Performance:
  - P95 latence < 2s par requête agent
  - Throughput: 100+ requêtes/min
```

---

## 🐛 Risques & Mitigation

| Risque | Sévérité | Mitigation |
|--------|----------|-----------|
| Injection Python | CRITIQUE | Docker sandbox + whitelist regex |
| Backward compat (outils) | HAUTE | Migration auto: tous les outils par défaut |
| Perf recherche RAG | MOYENNE | Index vectoriel + pagination |
| Token costs (LLM) | HAUTE | Quota strict + monitoring |
| Dépendance Docker | MOYENNE | Image légère, fallback?? |

---

## 🗺️ Dépendances Externes

### Déjà présentes ✅
- `symfony/http-kernel` (Voters, EventSubscriber)
- `doctrine/orm` (Entités)
- EmbeddingService (pour RAG)

### À ajouter ⚠️
- `symfony/process` (PythonSandboxService)
- Docker daemon accessible
- Python 3.11+ image Docker

---

## 📝 Notes pour Futures Sessions

- [ ] Vérifier état EmbeddingService avant Phase 2
- [ ] Benchmarker overhead Docker (création container)
- [ ] Discuter rate limiting strategy (token-based? request-based?)
- [ ] Prévoir alertes si quota atteint
- [ ] Documenter API pour créer nouveaux Agents
- [ ] Créer exemple Agent "Data Analyst" pour démo

---

## 📌 Liens & Références

- **Gemini Brainstorm**: [Rapport original]
- **SynapseMission entity**: src/Storage/Entity/SynapseMission.php
- **ChatService**: src/Core/ChatService.php
- **AiToolInterface**: src/Contract/AiToolInterface.php
- **EmbeddingService**: (localiser)

---

**Last Updated**: 2026-03-07
**Next Review**: 2026-03-21 (après Phase 1)
