# list_models

Liste tous les modèles LLM déclarés dans les catalogues YAML Synapse, groupés par provider.

## Namespace

```
ArnaudMoncondhuy\SynapseMcp\Tool\ListModelsTool
```

## Prérequis

Requiert `PermissionCheckerInterface::canAccessAdmin() = true`.

## Paramètres

| Paramètre | Type | Défaut | Description |
|-----------|------|--------|-------------|
| `providerFilter` | `?string` | `null` | Filtrer les résultats par slug de provider (ex: `gemini`, `ovh`) |

## Réponse

```json
{
  "status": "success",
  "count": 12,
  "providerFilter": null,
  "models": {
    "gemini": [
      {
        "modelId": "gemini-2.5-flash",
        "label": "Gemini 2.5 Flash",
        "provider": "gemini",
        "isEnabled": true,
        "inDb": true,
        "pricing": {
          "input": 0.15,
          "output": 0.60,
          "outputImage": null,
          "currency": "USD"
        },
        "capabilities": {
          "textGeneration": true,
          "embedding": false,
          "imageGeneration": false,
          "thinking": true,
          "functionCalling": true,
          "parallelToolCalls": true,
          "responseSchema": true,
          "streaming": true,
          "systemPrompt": true,
          "safetySettings": true,
          "topK": true,
          "vision": true,
          "acceptedMimeTypes": ["image/png", "image/jpeg", "application/pdf"]
        },
        "limits": {
          "maxInputTokens": 1048576,
          "maxOutputTokens": 65536,
          "contextWindow": 1048576
        },
        "embeddingDimensions": null,
        "providerRegions": ["us-central1"],
        "rgpdRisk": "high",
        "deprecatedAt": null
      }
    ]
  },
  "timestamp": "2026-04-11T10:00:00+00:00"
}
```

## Logique d'activation

L'état `isEnabled` est calculé en superposant la table `synapse_model` (overrides admin) sur les valeurs par défaut du catalogue YAML :
- Un modèle absent de la table est activé par défaut
- Un modèle présent avec `isEnabled=false` est désactivé

Pour activer ou désactiver un modèle, utiliser l'interface admin (`/synapse/admin`).

## Voir aussi

- [`list_presets`](list-presets.md) — presets associés aux modèles
- [`create_sandbox_preset`](sandbox.md#create_sandbox_preset) — créer un preset temporaire pour un modèle
