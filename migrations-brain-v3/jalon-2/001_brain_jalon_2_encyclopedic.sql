-- ============================================================================
-- Brain v3 — Jalon 2 : table encyclopédique (cortex temporal)
-- ============================================================================
--
-- SQL de référence en PostgreSQL pour les apps hôtes. Préfixe `syn_` par
-- défaut (configurable via `synapse.persistence.table_prefix`).
--
-- Crée :
--   - syn_brain_neuron_encyclopedic (cortex temporal — chunks indexables)
--
-- Type UUID natif PostgreSQL ; pour MySQL/MariaDB, remplacer par CHAR(36).
-- JSONB privilégié (parsing au INSERT, indexable GIN, plus rapide en lecture).
--
-- Si vous avez surchargé synapse.persistence.table_prefix dans votre app
-- hôte, remplacez `syn_` par votre préfixe dans tout ce fichier.
-- ============================================================================

-- ── 1. syn_brain_neuron_encyclopedic (cortex temporal) ──────────────────────
CREATE TABLE syn_brain_neuron_encyclopedic (
    id              UUID         NOT NULL PRIMARY KEY,
    source_uuid     UUID         NOT NULL,
    document_ref    VARCHAR(255) NOT NULL,
    chunk_index     INTEGER      NOT NULL,
    total_chunks    INTEGER      NOT NULL,
    chunk_content   TEXT         NOT NULL,
    embedding       JSONB        NOT NULL,
    doc_metadata    JSONB        NULL,

    CONSTRAINT fk_brain_neuron_encyclopedic_source
        FOREIGN KEY (source_uuid) REFERENCES syn_brain_memory_source (id)
        ON DELETE CASCADE
);

CREATE INDEX idx_brain_neuron_encyclopedic_source         ON syn_brain_neuron_encyclopedic (source_uuid);
CREATE INDEX idx_brain_neuron_encyclopedic_doc_ref        ON syn_brain_neuron_encyclopedic (document_ref);
CREATE INDEX idx_brain_neuron_encyclopedic_source_chunk   ON syn_brain_neuron_encyclopedic (source_uuid, chunk_index);

COMMENT ON TABLE syn_brain_neuron_encyclopedic IS 'Aire Cortex temporal — chunk indexable d''un document — design §4. Absorbe la sémantique de l''ancienne table synapse_rag_document (retrait prévu jalon 8).';
