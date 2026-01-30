<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Contract;

/**
 * Interface pour le service de chiffrement
 *
 * Permet au bundle de supporter différentes stratégies de chiffrement
 * ou de désactiver complètement le chiffrement (NullEncryptionService).
 *
 * @example
 * ```php
 * // Implémentation libsodium (par défaut)
 * $encrypted = $encryptionService->encrypt('Message secret');
 * $decrypted = $encryptionService->decrypt($encrypted);
 *
 * // Vérifier si un texte est chiffré
 * if ($encryptionService->isEncrypted($data)) {
 *     $data = $encryptionService->decrypt($data);
 * }
 * ```
 */
interface EncryptionServiceInterface
{
    /**
     * Chiffre un texte en clair
     *
     * @param string $plaintext Texte à chiffrer
     * @return string Texte chiffré (format dépend de l'implémentation)
     *
     * @throws \RuntimeException Si le chiffrement échoue
     */
    public function encrypt(string $plaintext): string;

    /**
     * Déchiffre un texte chiffré
     *
     * @param string $ciphertext Texte chiffré
     * @return string Texte en clair
     *
     * @throws \RuntimeException Si le déchiffrement échoue (clé invalide, données corrompues)
     */
    public function decrypt(string $ciphertext): string;

    /**
     * Vérifie si une donnée est chiffrée
     *
     * Permet de détecter si une donnée a déjà été chiffrée pour éviter
     * un double chiffrement ou pour gérer une migration progressive.
     *
     * @param string $data Donnée à vérifier
     * @return bool True si la donnée est chiffrée, false sinon
     */
    public function isEncrypted(string $data): bool;
}
