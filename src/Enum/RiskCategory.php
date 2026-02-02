<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Enum;

/**
 * Catégorie de risque détecté (Système "Ange Gardien")
 */
enum RiskCategory: string
{
    /**
     * Menace suicidaire ou auto-mutilation
     */
    case SUICIDE = 'SUICIDE';

    /**
     * Harcèlement, intimidation, violence verbale
     */
    case HARASSMENT = 'HARASSMENT';

    /**
     * Violence physique, menaces
     */
    case VIOLENCE = 'VIOLENCE';

    /**
     * Terrorisme, radicalisation
     */
    case TERRORISM = 'TERRORISM';

    /**
     * Activités illégales
     */
    case ILLEGAL = 'ILLEGAL';

    /**
     * Exploitation, abus sexuel
     */
    case EXPLOITATION = 'EXPLOITATION';

    /**
     * Détresse psychologique
     */
    case DISTRESS = 'DISTRESS';

    /**
     * Autre risque
     */
    case OTHER = 'OTHER';

    /**
     * Retourne l'émoji associé
     */
    public function getEmoji(): string
    {
        return match ($this) {
            self::SUICIDE => '🆘',
            self::HARASSMENT => '😡',
            self::VIOLENCE => '👊',
            self::TERRORISM => '💣',
            self::ILLEGAL => '⚖️',
            self::EXPLOITATION => '🚫',
            self::DISTRESS => '😰',
            self::OTHER => '⚠️',
        };
    }

    /**
     * Retourne le label français
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::SUICIDE => 'Menace suicidaire',
            self::HARASSMENT => 'Harcèlement',
            self::VIOLENCE => 'Violence',
            self::TERRORISM => 'Terrorisme',
            self::ILLEGAL => 'Activité illégale',
            self::EXPLOITATION => 'Exploitation',
            self::DISTRESS => 'Détresse psychologique',
            self::OTHER => 'Autre',
        };
    }

    /**
     * Retourne la description
     */
    public function getDescription(): string
    {
        return match ($this) {
            self::SUICIDE => 'Menace de suicide, auto-mutilation, comportement auto-destructeur',
            self::HARASSMENT => 'Harcèlement, intimidation, violence verbale, discrimination',
            self::VIOLENCE => 'Violence physique, menaces, agression',
            self::TERRORISM => 'Terrorisme, radicalisation, extrémisme violent',
            self::ILLEGAL => 'Activités illégales, fraude, trafic',
            self::EXPLOITATION => 'Exploitation, abus sexuel, pédophilie',
            self::DISTRESS => 'Détresse psychologique importante, anxiété sévère',
            self::OTHER => 'Autre situation préoccupante nécessitant une attention',
        };
    }

    /**
     * Retourne la priorité d'intervention (1 = max, 5 = min)
     */
    public function getPriority(): int
    {
        return match ($this) {
            self::SUICIDE => 1,
            self::EXPLOITATION => 1,
            self::VIOLENCE => 2,
            self::TERRORISM => 2,
            self::DISTRESS => 3,
            self::HARASSMENT => 3,
            self::ILLEGAL => 4,
            self::OTHER => 5,
        };
    }
}
