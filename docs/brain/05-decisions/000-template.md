---
status: template
date: YYYY-MM-DD
jalon: N
---

# ADR-NNN — Titre court à l'impératif

> Tout ADR remplace ce template. Ne pas modifier ce fichier — le copier sous un nouveau nom `NNN-titre-kebab.md`.

## Statut

`proposé` | `accepté` | `superseded by [ADR-MMM](MMM-titre.md)` | `abandonné`

## Contexte

Décrire le problème en 2-5 phrases. Pourquoi cette décision est-elle nécessaire **maintenant** ? Quel est le coût de ne pas trancher ?

Citer la section pertinente du design (`docs/brain-v3-design.md` §X) ou de la charte (`docs/brain/00-charte.md` §X) qui motive le besoin de décision.

## Options considérées

### Option A — Nom court

**Description** — 2-3 phrases.

**Avantages** :
- Point 1
- Point 2

**Inconvénients** :
- Point 1
- Point 2

### Option B — Nom court

Idem.

### Option C — Nom court (si pertinent)

Idem.

## Décision

**Option retenue : X.**

Justification en 2-5 phrases. Insister sur le critère qui a tranché. Si la décision s'écarte du design figé, expliquer pourquoi.

## Conséquences

- **Code impacté** : fichiers, classes, tables affectés
- **Migrations** : besoin de migration data ? scripts ?
- **Tests** : nouveaux tests à ajouter ? Tests à supprimer ?
- **Documentation** : pages à mettre à jour (`packages/core/docs/`, README)
- **ADRs à suivre** : décisions ouvertes par celle-ci, à trancher dans des ADRs ultérieurs

## Notes

Liens vers paper, code OSS, discussion, mémoire user, etc.

---

*Décidé avec [arnaud@…] le YYYY-MM-DD.*
