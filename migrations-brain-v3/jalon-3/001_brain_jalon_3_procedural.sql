-- ============================================================================
-- Brain v3 — Jalon 3 : table procédurale (ganglions de la base)
-- ============================================================================
--
-- SQL de référence en PostgreSQL pour les apps hôtes. Préfixe `syn_` par
-- défaut (configurable via `synapse.persistence.table_prefix`).
--
-- Crée :
--   - syn_brain_neuron_procedural (ganglions — workflows typés)
--
-- Type UUID natif PostgreSQL ; pour MySQL/MariaDB, remplacer par CHAR(36).
-- JSONB privilégié (parsing au INSERT, indexable GIN).
--
-- Si vous avez surchargé synapse.persistence.table_prefix, remplacez `syn_`
-- par votre préfixe dans tout ce fichier.
-- ============================================================================

-- ── 1. syn_brain_neuron_procedural (ganglions de la base) ───────────────────
CREATE TABLE syn_brain_neuron_procedural (
    id                 UUID                NOT NULL PRIMARY KEY,
    source_uuid        UUID                NULL,
    name               VARCHAR(255)        NOT NULL,
    trigger_pattern    JSONB               NOT NULL,
    steps              JSONB               NOT NULL,
    conditions         JSONB               NOT NULL,
    success_rate       DOUBLE PRECISION    NOT NULL,
    execution_count    INTEGER             NOT NULL,
    last_executed_at   TIMESTAMP(0)        NULL,

    CONSTRAINT fk_brain_neuron_procedural_source
        FOREIGN KEY (source_uuid) REFERENCES syn_brain_memory_source (id)
        ON DELETE SET NULL
);

CREATE INDEX idx_brain_neuron_procedural_source         ON syn_brain_neuron_procedural (source_uuid);
CREATE INDEX idx_brain_neuron_procedural_name           ON syn_brain_neuron_procedural (name);
CREATE INDEX idx_brain_neuron_procedural_last_executed  ON syn_brain_neuron_procedural (last_executed_at);

COMMENT ON TABLE syn_brain_neuron_procedural IS 'Aire Ganglions de la base — workflow typé avec successRate Hebbien — design §4. FK ON DELETE SET NULL (procédure manuelle peut survivre à la source).';
