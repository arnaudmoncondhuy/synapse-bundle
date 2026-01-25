# SynapseBundle - Guide d'Utilisation

Ce document détaille comment intégrer et étendre `SynapseBundle` dans votre application Symfony.

## 🧠 Architecture des Prompts (3 Couches)

Le bundle utilise une architecture en 3 couches pour construire le contexte de l'IA :

1.  **Prompt Technique (Interne)** : Géré par le Bundle. Injecte les règles strictes de formatage (blocs `<thinking>`) et de sécurité. Vous n'avez pas à vous en soucier, mais sachez qu'il est toujours présent en premier.
2.  **Prompt Système (Applicatif)** : C'est ici que vous définissez **votre** contexte. Qui est l'IA ? Quelle est la date ? Quelles sont les règles métier ?
3.  **Prompt Utilisateur** : La demande de l'utilisateur final.

---

## 🛠️ Personnaliser le Contexte (Prompt Système)

Par défaut, le bundle utilise un contexte minimal (Date + "Tu es un assistant utile").
Pour définir votre propre contexte (ex: "Tu es un expert en Symfony"), vous devez implémenter `ContextProviderInterface`.

### 1. Créer votre Provider

```php
// src/Service/MyAppContextProvider.php
namespace App\Service;

use ArnaudMoncondhuy\SynapseBundle\Contract\ContextProviderInterface;

class MyAppContextProvider implements ContextProviderInterface
{
    public function getSystemPrompt(): string
    {
        // Vous pouvez injecter d'autres services ici (ex: UserContext, Config...)
        $date = (new \DateTime())->format('d/m/Y H:i');
        
        return <<<PROMPT
Tu es l'assistant virtuel de l'application "MonSiteWeb".
Nous sommes le {$date}.

Tes objectifs :
1. Aider les utilisateurs à naviguer.
2. Répondre de manière courtoise.
PROMPT;
    }

    public function getInitialContext(): array
    {
        return [];
    }
}
```

### 2. Surcharger le service par défaut

Dans votre `services.yaml` :

```yaml
services:
    # ...

    # Dire au bundle d'utiliser VOTRE provider à la place de celui par défaut
    ArnaudMoncondhuy\SynapseBundle\Contract\ContextProviderInterface:
        alias: App\Service\MyAppContextProvider
```

---

## 🔧 Créer des Outils (Tools)

Les outils permettent à l'IA d'interagir avec votre code (ex: chercher en base de données, envoyer un mail).

Il suffit d'implémenter `AiToolInterface`. Le bundle détectera automatiquement tous les services implémentant cette interface.

```php
// src/Service/Tool/ProductSearchTool.php
namespace App\Service\Tool;

use ArnaudMoncondhuy\SynapseBundle\Contract\AiToolInterface;

class ProductSearchTool implements AiToolInterface
{
    public function getName(): string 
    { 
        return 'search_products'; // Nom unique pour l'IA
    }

    public function getDescription(): string 
    { 
        return 'Recherche des produits par mot-clé.'; 
    }

    public function getInputSchema(): array 
    { 
        // Schéma JSON Schema pour les paramètres
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Le mot clé de recherche'],
                'limit' => ['type' => 'integer', 'description' => 'Nombre max de résultats'],
            ],
            'required' => ['query']
        ];
    }

    public function execute(array $parameters): mixed 
    {
        $query = $parameters['query'];
        // ... Logique de recherche ...
        return ['result 1', 'result 2'];
    }
}
```

---

## 💾 Gestion de l'Historique

Par défaut, l'historique est stocké en **Session**.
Si vous voulez stocker les conversations en **Base de Données**, implémentez `ConversationHandlerInterface`.

```php
// src/Service/DatabaseConversationHandler.php
namespace App\Service;

use ArnaudMoncondhuy\SynapseBundle\Contract\ConversationHandlerInterface;

class DatabaseConversationHandler implements ConversationHandlerInterface
{
    public function loadHistory(): array { /* ... SELECT ... */ }
    public function saveHistory(array $history): void { /* ... INSERT/UPDATE ... */ }
    public function clearHistory(): void { /* ... DELETE ... */ }
}
```

Puis surchargez l'alias dans votre `services.yaml` :

```yaml
services:
    ArnaudMoncondhuy\SynapseBundle\Contract\ConversationHandlerInterface:
        alias: App\Service\DatabaseConversationHandler
```

---

## 🔑 Sécurisation de la clé API (Multi-tenant)

C'est l'aspect le plus critique pour la sécurité. Ne passez **JAMAIS** la clé API via le frontend. 
Le bundle utilise `ApiKeyProviderInterface` pour récupérer la clé côté serveur.

### 1. Mode Global (Fichier .env)

Si vous utilisez la même clé pour tout le projet, configurez-la simplement dans `config/packages/synapse.yaml` :

```yaml
synapse:
    api_key: '%env(GEMINI_API_KEY)%'
```

### 2. Mode Dynamique (Par utilisateur)

Si chaque utilisateur a sa propre clé, implémentez le provider :

```php
// src/Service/UserApiKeyProvider.php
namespace App\Service;

use ArnaudMoncondhuy\SynapseBundle\Contract\ApiKeyProviderInterface;
use Symfony\Bundle\SecurityBundle\Security;

class UserApiKeyProvider implements ApiKeyProviderInterface
{
    public function __construct(private Security $security) {}

    public function provideApiKey(): ?string
    {
        $user = $this->security->getUser();
        // Récupérer la clé de l'utilisateur (ex: en BDD)
        return $user?->getGeminiApiKey();
    }
}
```

Symfony détectera automatiquement votre service et l'utilisera.

---

## 🎨 Assets & Stimulus

Le bundle utilise **AssetMapper** et **Stimulus**.

### 1. Installation des dépendances JS

Si vous utilisez Symfony Flex, les contrôleurs devraient être détectés. Sinon, ou pour forcer la mise à jour :

```bash
php bin/console importmap:require @arnaudmoncondhuy/synapse-bundle
```

### 2. Import dans votre application

Assurez-vous d'importer le CSS (si pas déjà fait via le composant Twig) et d'enregistrer le contrôleur dans votre `assets/app.js` ou `assets/bootstrap.js` :

```javascript
// assets/bootstrap.js
import { startStimulusApp } from '@symfony/stimulus-bundle';

const app = startStimulusApp();
// Les contrôleurs du bundle sont chargés automatiquement via controllers.json
```

### 3. Vérification

Vous pouvez vérifier que les assets sont bien chargés :

```bash
php bin/console debug:asset-map
```

> [!NOTE]
> Si vous utilisez le bundle en tant que **dépôt local** (path repository) et que vous rencontrez l'erreur "Failed to resolve module specifier" dans la console du navigateur, assurez-vous que `importmap:require` a bien fonctionné ou ajoutez manuellement l'entrée dans votre `importmap.php` :
> ```php
> 'synapse/controllers/chat_controller.js' => ['path' => 'synapse/controllers/chat_controller.js'],
> ```
> (Normalement, le bundle tente de le faire automatiquement via son Extension).
