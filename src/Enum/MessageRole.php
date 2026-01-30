<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Enum;

/**
 * Rôle d'un message dans une conversation
 */
enum MessageRole: string
{
    /**
     * Message envoyé par l'utilisateur
     */
    case USER = 'USER';

    /**
     * Message généré par le modèle IA
     */
    case MODEL = 'MODEL';

    /**
     * Message système (instructions, contexte)
     */
    case SYSTEM = 'SYSTEM';

    /**
     * Appel ou réponse de fonction (Function Calling)
     */
    case FUNCTION = 'FUNCTION';

    /**
     * Vérifie si le rôle est affiché dans l'interface
     */
    public function isDisplayable(): bool
    {
        return match ($this) {
            self::USER, self::MODEL => true,
            self::SYSTEM, self::FUNCTION => false,
        };
    }

    /**
     * Retourne l'émoji associé au rôle
     */
    public function getEmoji(): string
    {
        return match ($this) {
            self::USER => '👤',
            self::MODEL => '🤖',
            self::SYSTEM => '⚙️',
            self::FUNCTION => '🔧',
        };
    }

    /**
     * Retourne le label français
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::USER => 'Utilisateur',
            self::MODEL => 'Assistant',
            self::SYSTEM => 'Système',
            self::FUNCTION => 'Fonction',
        };
    }
}
