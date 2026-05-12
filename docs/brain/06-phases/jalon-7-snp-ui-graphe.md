---
statut: squelette
ouvert: 2026-05-12
livré: —
---

# Jalon 7 — SNP (webhooks I/O) + UI graphe d'audit

> Squelette. À affiner avant exécution.

## 1. Capacité d'association visée

**Auditabilité totale + I/O distincte.** Webhook entrant agnostique → MemorySource → neurones (les 5 aires d'association sont en place). Webhook sortant pour `MotorNeuron` planifié. UI "graphe de mémoire" qui affiche les synapses + neurones cliquables (lecture + édition de poids manuelle).

## 2. Test de sortie

```bash
# Webhook entrant
curl -X POST -d @payload.json http://localhost/brain/webhook/inbound/<provider>
# → MemorySource créée + neurones extraits

# UI graphe
# Visiter /admin/brain/graph → afficher un sous-graphe autour d'un neurone
# Cliquer sur une synapse → modal qui montre weight + polarity + relation_type
# Modifier le weight → persiste en BDD
```

## 3. Pré-requis

- Jalon 6 livré (functional networks)
- 2 aires de transduction à ajouter : `SensoryNeuron` + `MotorNeuron`
- ADR-XXX : intégration avec le panneau Admin V2 existant (sous-section "Brain") ou page dédiée ?

## 4. Surface à concevoir

- `Brain\Webhook\InboundWebhookController` (agnostique, validation HMAC paramétrable)
- `Brain\Webhook\OutboundEventDispatcher` (lit `MotorNeuron` status=planned, dispatch)
- Entités `SensoryNeuron`, `MotorNeuron`
- UI graphe : composant Stimulus + Cytoscape.js (ou D3) + endpoints REST
- `Brain\AuditLog` entité (table `brain_audit_log`)

## 5. Étapes d'implémentation

À détailler. **Recommandation** : ouvrir Plan mode (front + back + nouveau pattern webhook).

## 6. Tests & dogfooding

### Validation qualité synapses

- 100% des synapses produites doivent être visibles dans l'UI
- Test fonctionnel UI : créer 50 neurones + 100 synapses, vérifier rendu + perf
- Test webhook : 3 providers fictifs envoient simultanément, vérifier idempotence

## 7. Décisions ouvertes

- ADR-XXX : moteur de rendu graphe (Cytoscape.js, Sigma, D3) — perf vs ergonomie
- ADR-XXX : édition manuelle de synapse → traçabilité dans `AuditLog` obligatoire
- ADR-XXX : webhook outbound retry strategy

## 8. Hors-scope

- Consolidation cron (jalon 8)
- Validation-gated learning (jalon 8)

## 9. Bilan

*À remplir à la fin du jalon.*
