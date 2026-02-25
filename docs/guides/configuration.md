# Configuration détaillée

Toute la configuration du bundle se fait dans le fichier `config/packages/synapse.yaml`.

## Référence des options

### Persistance (`persistence`)

Permet d'enregistrer les conversations et messages en base de données.

| Option | Type | Défaut | Description |
|---|---|---|---|
| `enabled` | bool | `false` | Activer la persistance Doctrine. |
| `conversation_class` | string | `null` | FQCN de votre entité Conversation (ex: `App\Entity\Conversation`). |
| `message_class` | string | `null` | FQCN de votre entité Message. |

### Sécurité (`security`)

Gère les accès à l'administration et au chat.

| Option | Type | Défaut | Description |
|---|---|---|---|
| `admin_role` | string | `ROLE_ADMIN` | Le rôle requis pour accéder à `/synapse/admin`. |
| `permission_checker` | string | `default` | Service utilisé pour vérifier les droits d'accès. |

### Chiffrement (`encryption`)

Pour sécuriser vos clés API et vos messages en base de données.

```yaml
synapse:
    encryption:
        enabled: true
        key: '%env(SYNAPSE_ENCRYPTION_KEY)%'
```

> [!WARNING]
> N'activez le chiffrement que si vous avez configuré une clé 32 bytes valide.

### Rétention RGPD (`retention`)

Suppression automatique des anciennes conversations.

| Option | Type | Défaut | Description |
|---|---|---|---|
| `days` | int | `30` | Nombre de jours avant que les conversations ne soient purgées par `synapse:purge`. |

## Variables d'environnement

Voici les variables recommandées à définir dans votre `.env` :

```env
# Clé de chiffrement (32 bytes)
SYNAPSE_ENCRYPTION_KEY=...
# Rôle admin custom
SYNAPSE_ADMIN_ROLE=ROLE_SUPER_ADMIN
```

## Vérification

Vous pouvez voir la configuration finale résolue par Symfony avec la commande :

```bash
php bin/console config:dump synapse
```
