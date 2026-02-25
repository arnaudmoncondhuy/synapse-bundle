# VectorStoreInterface

L'interface `VectorStoreInterface` est le socle du système de RAG (Retrieval-Augmented Generation) dans SynapseBundle. Elle définit comment stocker et rechercher des informations "vectorisées" (embeddings) pour donner une mémoire long-terme à votre IA.

## 🛠 Pourquoi l'utiliser ?

*   **Mémoire illimitée** : Permet à l'IA d'accéder à des milliers de documents sans saturer la fenêtre de contexte.
*   **Recherche sémantique** : Trouve des informations basées sur le **sens** plutôt que sur des simples mots-clés.
*   **Performances** : Délègue la recherche complexe à des moteurs spécialisés (Pinecone, Weaviate, PostgreSQL avec pgvector).

---

## 📋 Résumé du Contrat

| Méthode | Entrée | Sortie | Rôle |
| :--- | :--- | :--- | :--- |
| `add(array $vectors)` | Liste d'objets `Embedding` | `void` | Insère de nouvelles données dans la base vectorielle. |
| `search(array $vector, int $limit)` | Vecteur de recherche | `array` | Récupère les documents les plus proches sémantiquement. |
| `delete(array $ids)` | Liste d'identifiants | `void` | Supprime des entrées spécifiques. |
| `clear()` | - | `void` | Réinitialise complètement le store. |

---

## 🚀 Exemple : Implémentation simplifiée en mémoire

=== "InMemoryVectorStore.php"

    ```php
    namespace App\Synapse\Vector;

    use ArnaudMoncondhuy\SynapseBundle\Contract\VectorStoreInterface;

    class InMemoryVectorStore implements VectorStoreInterface
    {
        private array $storage = [];

        public function add(array $vectors): void
        {
            foreach ($vectors as $v) {
                // $v['id'], $v['vector'], $v['metadata']
                $this->storage[$v['id']] = $v;
            }
        }

        public function search(array $vector, int $limit = 5): array
        {
            // Ici, vous implémenteriez un calcul de similarité cosinus.
            // Pour l'exemple, on retourne les 5 premiers éléments.
            return array_slice($this->storage, 0, $limit);
        }

        public function delete(array $ids): void
        {
            foreach ($ids as $id) unset($this->storage[$id]);
        }

        public function clear(): void { $this->storage = []; }
    }
    ```

---

## 💡 Conseils d'implémentation

> [!TIP]
> **Métadonnées** : La méthode `add` reçoit un champ `metadata`. Utilisez-le pour stocker le texte source ou l'URL du document original. Cela facilitera l'affichage des sources par l'IA.

*   **Identifiants** : Gérez soigneusement les IDs pour éviter les doublons lors de la mise à jour de vos documents.
*   **Dimensionalité** : Assurez-vous que votre Store accepte la même dimension de vecteur que celle générée par votre `EmbeddingClientInterface` (ex: 1536 pour OpenAI).

---

## 🔍 Référence API complète

::: ArnaudMoncondhuy\SynapseBundle\Contract\VectorStoreInterface
