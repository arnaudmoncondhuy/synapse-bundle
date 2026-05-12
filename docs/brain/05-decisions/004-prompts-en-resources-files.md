---
status: accepté
date: 2026-05-12
jalon: 2
---

# ADR-004 — Prompts d'extracteurs en fichiers Resources, pas en BDD

## Statut

`accepté` — décidé pendant l'affinage du plan jalon 2.

## Contexte

Les extracteurs Brain v3 (jalon 2 et suivants) ont besoin de prompts LLM longs et précis. Où vivent ces prompts ? Trois lieux possibles :

1. Constantes PHP dans la classe extracteur
2. Fichiers de ressources dans `Resources/`
3. Entités Doctrine (en BDD), éditables via admin

## Options considérées

### Option A — Constantes PHP dans la classe

```php
final class SemanticExtractor {
    private const PROMPT = "Tu es un extracteur de faits...";
}
```

**Avantages** : simple, versionné Git, pas de dépendance fichier.

**Inconvénients** : strings multilignes en PHP polluent la lisibilité de la classe ; édition pénible ; pas de coloration markdown des prompts.

### Option B — Fichiers Resources (`.md` + `.json`)

```
Resources/brain/prompts/
├── extract-semantic.md          (prompt système, markdown)
├── extract-semantic.schema.json (JSON schema)
├── extract-episodic.md
└── extract-episodic.schema.json
```

Chaque extracteur charge ses 2 fichiers au boot.

**Avantages** : prompts lisibles + colorés en éditeur ; séparation données/code claire ; pas de quote-escaping ; testable via `file_get_contents` mocké au besoin ; édition humaine facile.

**Inconvénients** : 1 indirection (lecture fichier au runtime) ; cache opcache à invalider sur déploiement ; doit gérer le chemin.

### Option C — Entités Doctrine + admin UI

`syn_core_brain_prompt` table, admin pour CRUD, versioning.

**Avantages** : édition à chaud sans deploy ; A/B testing possible ; historique versionné.

**Inconvénients** : sur-engineering au jalon 2 ; aucun cas concret de mod à chaud aujourd'hui ; multiplie les chemins (un prompt qui n'est pas en BDD = comportement par défaut ? fallback ?).

## Décision

**Option B retenue : fichiers Resources `Resources/brain/prompts/`.**

Justification :

1. **YAGNI** (charte §2.4) — pas de cas réel qui justifie la BDD au jalon 2
2. **Cohérent avec le pattern bundle** : `Resources/views/` pour Twig, `Resources/translations/` pour traductions, etc.
3. **Versionné Git naturellement** : un changement de prompt = un commit, traçable
4. **Édition humaine confortable** : markdown coloré dans l'éditeur, JSON schema séparé pour validation IDE
5. **Migration BDD reportée** : si A/B testing ou mod à chaud devient nécessaire (jalon 5+), facile à introduire en parallèle (le code charge depuis BDD si présent, sinon fallback Resources)

Implémentation :

```php
final class SemanticExtractor {
    private const PROMPT_PATH = __DIR__ . '/../../Resources/brain/prompts/extract-semantic.md';
    private const SCHEMA_PATH = __DIR__ . '/../../Resources/brain/prompts/extract-semantic.schema.json';

    public function __construct(
        private readonly ChatService $chatService,
        // … autres deps
    ) {}

    public function extract(MemorySource $source, BrainArea $area): ExtractionResult {
        $prompt = file_get_contents(self::PROMPT_PATH);
        $schema = json_decode(file_get_contents(self::SCHEMA_PATH), true, flags: JSON_THROW_ON_ERROR);

        $result = $this->chatService->ask($this->buildMessage($source, $prompt), [
            'structured_output' => $schema,
            'module' => 'brain',
            'action' => 'extract_semantic',
        ]);
        // … construire SemanticNeuron[]
    }
}
```

## Conséquences

- **Code impacté** : chaque extracteur charge ses prompts au runtime ; un helper `PromptLoader` pourrait être factorisé plus tard
- **Migrations** : aucune
- **Tests** : tests unitaires laissent `file_get_contents` lire le vrai fichier (les Resources sont versionnées Git, donc déterministes en test). Pas de mock fichier
- **Documentation** : ce répertoire `Resources/brain/prompts/` est documenté dans le README brain à la fin du jalon
- **ADRs à suivre** : si A/B testing devient nécessaire au jalon 5+, nouvel ADR sur le passage BDD (qui supersedera celui-ci ou cohabitera)

## Notes

- Limite de taille raisonnable : un prompt > 5000 caractères devient un signal qu'il faut le découper en plusieurs prompts spécialisés. Au jalon 2 on reste sur 1 prompt par aire (~1000 mots max)

---

*Décidé pendant l'affinage du plan jalon 2.*
