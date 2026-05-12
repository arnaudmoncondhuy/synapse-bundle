-- Initialisation BDD test Brain v3
--
-- Active pgvector dès l'init (les tests d'intégration utilisent les
-- opérateurs <-> / <=> de pgvector dès qu'on migrera les embeddings
-- depuis JSONB).

CREATE EXTENSION IF NOT EXISTS vector;
