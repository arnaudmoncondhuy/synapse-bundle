---
status: accepté
date: 2026-05-12
jalon: 2
---

# ADR-003 — Extracteur LLM via structured output (JSON schema)

## Statut

`accepté` — décidé pendant l'affinage du plan jalon 2.

## Contexte

Le jalon 2 introduit le premier appel LLM du brain : transformer une `MemorySource` brute en neurones structurés (faits sémantiques, événements épisodiques, etc.). Comment garantir que le LLM produit un JSON exploitable, robuste, sans hallucination de format ?

## Options considérées

### Option A — Parsing libre du texte LLM

Le LLM répond en texte libre, on parse côté code (regex / JSON inclus dans markdown).

**Inconvénients** :
- Format instable selon les modèles et les versions
- Échecs de parsing fréquents
- Logique parsing à maintenir

### Option B — Few-shot dans le prompt + parsing JSON

Prompt avec exemples de sortie JSON, on tente `json_decode` sur la réponse.

**Inconvénients** :
- Toujours dépendant du bon vouloir du LLM
- Pas de garantie schema
- Mieux que A, mais reste fragile

### Option C — Structured output natif (JSON schema)

`ChatService::ask` accepte déjà une option `structured_output` qui passe un JSON schema au provider LLM (supporté par Gemini, OpenAI 2024+, OVH selon model). Le provider garantit la conformité au schema.

**Avantages** :
- Garantie format au niveau provider
- Schema = contrat clair
- `ChatService` retourne directement un `array` parsé dans `$result['structured_output']`
- Compatible providers existants du bundle

**Inconvénients** :
- Nécessite un modèle qui supporte response_schema (vérifié via `ModelCapabilityRegistry`)
- Si le modèle ne supporte pas, l'extracteur doit échouer proprement

## Décision

**Option C retenue : structured output natif via `ChatService::ask(..., ['structured_output' => $jsonSchema])`.**

Chaque extracteur (`SemanticExtractor`, `EpisodicExtractor`) déclare son JSON schema et l'envoie à `ChatService::ask`. Le résultat arrive dans `$result['structured_output']` au format `array`, on construit les entités Brain à partir.

Si le modèle ne supporte pas response_schema, `ChatService::ask` lève une exception interceptée par l'extracteur qui rethrow `ExtractionFailedException` typée.

## Conséquences

- **Code impacté** : chaque `*Extractor` charge son schema JSON depuis `Resources/brain/prompts/<extractor>.json`, l'utilise dans l'appel à `ChatService::ask`
- **Migrations** : aucune
- **Tests** : tests unitaires stubbent `ChatService::ask` pour retourner un `structured_output` synthétique conforme au schema attendu
- **Documentation** : section "Décisions tranchées" du plan jalon 2 référence cet ADR
- **ADRs à suivre** :
  - Au jalon 3 : ADR sur le merge des schemas pour l'extracteur "1 passe unique" multi-aires

## Notes

- Liste des modèles supportant `response_schema` : voir `ModelCapabilityRegistry` côté core
- Si l'app hôte utilise un modèle sans support, fallback gracieux possible plus tard (re-prompt avec exemples JSON ; reporté car coûteux à maintenir)

---

*Décidé après lecture du `ChatService::ask` signature lors de l'affinage du plan jalon 2.*
