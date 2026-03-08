#!/bin/bash

# Couleurs pour la sortie console
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${YELLOW}Démarrage de la routine de test et lint...${NC}"

# 1. PHP-CS-Fixer
echo -e "\n${YELLOW}Étape 1: Vérification du style de code (PHP-CS-Fixer)...${NC}"
vendor/bin/php-cs-fixer fix --dry-run --diff
CS_EXIT=$?

if [ $CS_EXIT -eq 0 ]; then
    echo -e "${GREEN}✔ Style de code OK.${NC}"
else
    echo -e "${RED}✘ Problèmes de style détectés. Exécutez 'composer cs-fix' pour les corriger.${NC}"
fi

# 2. PHPStan
echo -e "\n${YELLOW}Étape 2: Analyse statique (PHPStan)...${NC}"
vendor/bin/phpstan analyse
STAN_EXIT=$?

if [ $STAN_EXIT -eq 0 ]; then
    echo -e "${GREEN}✔ PHPStan OK.${NC}"
else
    echo -e "${RED}✘ Des erreurs ont été trouvées par PHPStan.${NC}"
fi

# 3. PHPUnit
echo -e "\n${YELLOW}Étape 3: Tests unitaires et d'intégration (PHPUnit)...${NC}"
vendor/bin/phpunit
UNIT_EXIT=$?

if [ $UNIT_EXIT -eq 0 ]; then
    echo -e "${GREEN}✔ Tous les tests passent.${NC}"
else
    echo -e "${RED}✘ Certains tests ont échoué.${NC}"
fi

# Bilan
echo -e "\n----------------------------------------"
if [ $CS_EXIT -eq 0 ] && [ $STAN_EXIT -eq 0 ] && [ $UNIT_EXIT -eq 0 ]; then
    echo -e "${GREEN}TOUT EST OK ! Votre code est prêt.${NC}"
    exit 0
else
    echo -e "${RED}CERTAINES ÉTAPES ONT ÉCHOUÉ. Veuillez corriger les erreurs ci-dessus.${NC}"
    exit 1
fi
