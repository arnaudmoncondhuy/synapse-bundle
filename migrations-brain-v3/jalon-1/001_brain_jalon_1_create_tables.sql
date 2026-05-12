-- ============================================================================
-- Brain v3 — Jalon 1 : tables fondations
-- ============================================================================
--
-- SQL de référence en PostgreSQL pour les apps hôtes. Préfixe `syn_` par
-- défaut (configurable via `synapse.persistence.table_prefix`).
--
-- Crée 4 tables :
--   - syn_brain_memory_source    (point d'entrée stimuli)
--   - syn_brain_neuron_episodic  (Hippocampe — événements situés)
--   - syn_brain_neuron_semantic  (Néocortex — faits stables)
--   - syn_brain_synapse          (connexion polymorphe 5 dimensions)
--
-- Type UUID natif PostgreSQL ; pour MySQL/MariaDB, remplacer par CHAR(36).
-- JSONB privilégié (parsing au INSERT, indexable GIN, plus rapide en lecture).
-- Pour MySQL/MariaDB, remplacer par JSON.
--
-- Si vous avez surchargé synapse.persistence.table_prefix dans votre app
-- hôte, remplacez `syn_` par votre préfixe dans tout ce fichier.
-- ============================================================================

-- ── 1. syn_brain_memory_source ───────────────────────────────────────────────
CREATE TABLE syn_brain_memory_source (
    id              UUID         NOT NULL PRIMARY KEY,
    provider        VARCHAR(50)  NOT NULL,
    external_id     VARCHAR(255) NULL,
    raw_payload     JSONB        NOT NULL,
    received_at     TIMESTAMP(0) NOT NULL,
    owner_id        UUID         NULL
);

CREATE INDEX idx_brain_memory_source_provider     ON syn_brain_memory_source (provider);
CREATE INDEX idx_brain_memory_source_external_id  ON syn_brain_memory_source (external_id);
CREATE INDEX idx_brain_memory_source_received_at  ON syn_brain_memory_source (received_at);
CREATE INDEX idx_brain_memory_source_owner        ON syn_brain_memory_source (owner_id);

COMMENT ON TABLE syn_brain_memory_source IS 'Point d''entrée unique pour tout stimulus brut entrant — design §4';

-- ── 2. syn_brain_neuron_episodic (Hippocampe) ────────────────────────────────
CREATE TABLE syn_brain_neuron_episodic (
    id              UUID         NOT NULL PRIMARY KEY,
    source_uuid     UUID         NOT NULL,
    occurred_at     TIMESTAMP(0) NOT NULL,
    location        VARCHAR(255) NULL,
    actors          JSONB        NOT NULL,
    event_summary   TEXT         NOT NULL,
    embedding       JSONB        NOT NULL,
    sequence_id     UUID         NULL,

    CONSTRAINT fk_brain_neuron_episodic_source
        FOREIGN KEY (source_uuid) REFERENCES syn_brain_memory_source (id)
        ON DELETE CASCADE
);

CREATE INDEX idx_brain_neuron_episodic_source        ON syn_brain_neuron_episodic (source_uuid);
CREATE INDEX idx_brain_neuron_episodic_occurred_at   ON syn_brain_neuron_episodic (occurred_at);
CREATE INDEX idx_brain_neuron_episodic_sequence      ON syn_brain_neuron_episodic (sequence_id);

COMMENT ON TABLE syn_brain_neuron_episodic IS 'Aire Hippocampe — événement situé temps × lieu × acteurs';

-- ── 3. syn_brain_neuron_semantic (Néocortex) ────────────────────────────────
CREATE TABLE syn_brain_neuron_semantic (
    id                    UUID         NOT NULL PRIMARY KEY,
    source_uuids          JSONB        NOT NULL,  -- list<string RFC 4122>
    subject               TEXT         NOT NULL,
    predicate             TEXT         NOT NULL,
    value                 TEXT         NOT NULL,
    confidence            DOUBLE PRECISION NOT NULL,
    embedding             JSONB        NOT NULL,
    last_corroborated_at  TIMESTAMP(0) NOT NULL
);

CREATE INDEX idx_brain_neuron_semantic_subject       ON syn_brain_neuron_semantic (subject);
CREATE INDEX idx_brain_neuron_semantic_predicate     ON syn_brain_neuron_semantic (predicate);
CREATE INDEX idx_brain_neuron_semantic_corroborated  ON syn_brain_neuron_semantic (last_corroborated_at);

COMMENT ON TABLE syn_brain_neuron_semantic IS 'Aire Néocortex — fait stable subject-predicate-value, sources multiples possibles';

-- ── 4. syn_brain_synapse (connexion polymorphe) ──────────────────────────────
CREATE TABLE syn_brain_synapse (
    id                  UUID         NOT NULL PRIMARY KEY,
    source_neuron_area  VARCHAR(20)  NOT NULL,
    source_neuron_id    UUID         NOT NULL,
    target_neuron_area  VARCHAR(20)  NOT NULL,
    target_neuron_id    UUID         NOT NULL,
    weight              DOUBLE PRECISION NOT NULL,
    polarity            VARCHAR(16)  NOT NULL,
    relation_type       VARCHAR(20)  NOT NULL,
    confidence          DOUBLE PRECISION NOT NULL,
    evidence_count      INTEGER      NOT NULL,
    last_activated_at   TIMESTAMP(0) NOT NULL,
    context_id          UUID         NULL,
    edge_type           VARCHAR(16)  NOT NULL
);

CREATE INDEX idx_brain_synapse_source         ON syn_brain_synapse (source_neuron_area, source_neuron_id);
CREATE INDEX idx_brain_synapse_target         ON syn_brain_synapse (target_neuron_area, target_neuron_id);
CREATE INDEX idx_brain_synapse_relation_type  ON syn_brain_synapse (relation_type);
CREATE INDEX idx_brain_synapse_context        ON syn_brain_synapse (context_id);
CREATE INDEX idx_brain_synapse_last_activated ON syn_brain_synapse (last_activated_at);

COMMENT ON TABLE syn_brain_synapse IS 'Synapse polymorphe — 5 dimensions (weight, polarity, relation_type, confidence, evidence_count) cross-aires — design §6';
