<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Enum;

/**
 * Niveau de risque détecté dans une conversation (Système "Ange Gardien")
 */
enum RiskLevel: string
{
    /**
     * Aucun risque détecté
     */
    case NONE = 'NONE';

    /**
     * Risque mineur (surveillance recommandée)
     */
    case WARNING = 'WARNING';

    /**
     * Risque critique (intervention urgente nécessaire)
     */
    case CRITICAL = 'CRITICAL';

    /**
     * Vérifie si un risque a été détecté
     */
    public function hasRisk(): bool
    {
        return $this !== self::NONE;
    }

    /**
     * Retourne la couleur CSS associée
     */
    public function getColor(): string
    {
        return match ($this) {
            self::NONE => 'gray',
            self::WARNING => 'orange',
            self::CRITICAL => 'red',
        };
    }

    /**
     * Retourne l'émoji associé
     */
    public function getEmoji(): string
    {
        return match ($this) {
            self::NONE => '✅',
            self::WARNING => '⚠️',
            self::CRITICAL => '🚨',
        };
    }

    /**
     * Retourne le label français
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::NONE => 'Aucun risque',
            self::WARNING => 'Avertissement',
            self::CRITICAL => 'Critique',
        };
    }

    /**
     * Retourne tous les niveaux de risque nécessitant une attention
     */
    public static function pendingRisks(): array
    {
        return [self::WARNING, self::CRITICAL];
    }
}
