-- ============================================================================
-- Brain v3 — Jalon 3 : colonnes owner sur synapse (isolation user)
-- ============================================================================
--
-- ALTER de syn_brain_synapse pour ajouter le garde-fou isolation user
-- (ADR-006). Stockage redondant des owner_id des neurones source et target
-- pour 2 bénéfices :
--   1. Garde-fou au moment de la création (assertion code dans Synapse::__construct)
--   2. Filtrage rapide par owner sans jointure répétée (spreading activation jalon 4)
--
-- Si vous avez surchargé synapse.persistence.table_prefix, remplacez `syn_`
-- par votre préfixe dans tout ce fichier.
-- ============================================================================

ALTER TABLE syn_brain_synapse ADD COLUMN source_neuron_owner UUID NULL;
ALTER TABLE syn_brain_synapse ADD COLUMN target_neuron_owner UUID NULL;

CREATE INDEX idx_brain_synapse_source_owner ON syn_brain_synapse (source_neuron_owner);
CREATE INDEX idx_brain_synapse_target_owner ON syn_brain_synapse (target_neuron_owner);

COMMENT ON COLUMN syn_brain_synapse.source_neuron_owner IS 'Owner du neurone source (dénormalisé depuis MemorySource.ownerId). NULL = source open. ADR-006.';
COMMENT ON COLUMN syn_brain_synapse.target_neuron_owner IS 'Owner du neurone target (dénormalisé). NULL = target open. ADR-006.';
