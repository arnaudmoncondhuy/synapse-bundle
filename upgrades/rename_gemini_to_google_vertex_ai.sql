-- Migration : renommage du slug provider 'gemini' → 'google_vertex_ai'
-- À exécuter manuellement sur les bases de données existantes lors de la mise à jour.
--
-- Contexte : le provider Google Vertex AI était identifié par le slug 'gemini'.
-- Le slug a été corrigé en 'google_vertex_ai' pour plus de précision sémantique.

UPDATE synapse_provider
SET name = 'google_vertex_ai'
WHERE name = 'gemini';

UPDATE synapse_model
SET provider_name = 'google_vertex_ai'
WHERE provider_name = 'gemini';

UPDATE synapse_model_preset
SET provider_name = 'google_vertex_ai'
WHERE provider_name = 'gemini';
