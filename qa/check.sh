#!/bin/bash

# Se positionner à la racine du projet (dossier parent de qa/)
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR" || exit 1

# Couleurs pour la sortie console
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${YELLOW}Démarrage de la routine de test et lint...${NC}"

# 1. Composer Validation & Audit
echo -e "\n${YELLOW}Étape 1: Audit de sécurité et validation Composer...${NC}"
if command -v composer &> /dev/null; then
    composer validate --strict --quiet && composer audit
    COMPOSER_EXIT=$?
else
    echo -e "${YELLOW}⚠ Composer non trouvé (globalement), étape ignorée.${NC}"
    COMPOSER_EXIT=0
fi

if [ $COMPOSER_EXIT -eq 0 ]; then
    echo -e "${GREEN}✔ Composer OK.${NC}"
else
    echo -e "${RED}✘ Problèmes détectés par Composer.${NC}"
fi

# 2. Debug functions check (dd, dump, var_dump)
echo -e "\n${YELLOW}Étape 2: Détection des fonctions de debug (dd, dump)...${NC}"
DEBUG_SEARCH=$(grep -rE " (var_dump|dump|dd)\(" packages/ --exclude-dir=vendor --exclude-dir=tests --exclude-dir=Resources --include="*.php" | grep -v "Yaml::dump")
if [ -z "$DEBUG_SEARCH" ]; then
    echo -e "${GREEN}✔ Aucune fonction de debug trouvée.${NC}"
    DEBUG_EXIT=0
else
    echo -e "${RED}✘ Fonctions de debug trouvées :${NC}"
    echo "$DEBUG_SEARCH"
    DEBUG_EXIT=1
fi

# 3. PHP-CS-Fixer
echo -e "\n${YELLOW}Étape 3: Vérification du style de code (PHP-CS-Fixer)...${NC}"
vendor/bin/php-cs-fixer fix --config=qa/.php-cs-fixer.dist.php --dry-run --diff
CS_EXIT=$?

if [ $CS_EXIT -eq 0 ]; then
    echo -e "${GREEN}✔ Style de code OK.${NC}"
else
    echo -e "${RED}✘ Problèmes de style détectés. Exécutez 'composer cs-fix' pour les corriger.${NC}"
fi

# 4. PHPStan
echo -e "\n${YELLOW}Étape 4: Analyse statique (PHPStan)...${NC}"
vendor/bin/phpstan analyse -c qa/phpstan.neon
STAN_EXIT=$?

if [ $STAN_EXIT -eq 0 ]; then
    echo -e "${GREEN}✔ PHPStan OK.${NC}"
else
    echo -e "${RED}✘ Des erreurs ont été trouvées par PHPStan.${NC}"
fi

# 5. PHPUnit
echo -e "\n${YELLOW}Étape 5: Tests unitaires et d'intégration (PHPUnit)...${NC}"
vendor/bin/phpunit -c qa/phpunit.xml.dist
UNIT_EXIT=$?

if [ $UNIT_EXIT -eq 0 ]; then
    echo -e "${GREEN}✔ Tous les tests passent.${NC}"
else
    echo -e "${RED}✘ Certains tests ont échoué.${NC}"
fi

# 6. Stylelint (CSS Linting)
echo -e "\n${YELLOW}Étape 6: Lint CSS (Stylelint)...${NC}"
if command -v npx &> /dev/null && [ -f "qa/.stylelintrc.json" ]; then
    npx stylelint "packages/*/assets/**/*.css" --config qa/.stylelintrc.json --formatter compact
    STYLELINT_EXIT=$?
else
    echo -e "${YELLOW}⚠ Stylelint non disponible ou configuration manquante, étape ignorée.${NC}"
    STYLELINT_EXIT=0
fi

if [ $STYLELINT_EXIT -eq 0 ]; then
    echo -e "${GREEN}✔ Stylelint OK.${NC}"
else
    echo -e "${RED}✘ Erreurs CSS détectées par Stylelint.${NC}"
fi

# 7. PurgeCSS (Dead Code Detection)
echo -e "\n${YELLOW}Étape 7: Détection du CSS inutilisé (PurgeCSS)...${NC}"
if command -v npx &> /dev/null && [ -f "qa/purgecss.config.js" ]; then
    PURGE_OUTPUT=$(npx purgecss --config qa/purgecss.config.js --rejected 2>/dev/null)

    REJECTED_LIST=$(echo "$PURGE_OUTPUT" | grep "Rejected:" | grep -v "Rejected: \[\]" || true)

    if [ -z "$REJECTED_LIST" ]; then
        echo -e "${GREEN}✔ Aucun CSS inutilisé détecté.${NC}"
        PURGE_EXIT=0
    else
        echo -e "${YELLOW}⚠ Du CSS potentiellement inutilisé a été détecté :${NC}"
        echo "$REJECTED_LIST" | head -n 15
        echo -e "${YELLOW}Note: Vérifiez avant de supprimer, certaines classes peuvent être dynamiques.${NC}"
        PURGE_EXIT=0 # On ne bloque pas le build car PurgeCSS peut avoir des faux positifs
    fi
else
    echo -e "${YELLOW}⚠ PurgeCSS non disponible ou configuration manquante, étape ignorée.${NC}"
    PURGE_EXIT=0
fi

# Bilan
echo -e "\n----------------------------------------"
if [ $COMPOSER_EXIT -eq 0 ] && [ $DEBUG_EXIT -eq 0 ] && [ $CS_EXIT -eq 0 ] && [ $STAN_EXIT -eq 0 ] && [ $UNIT_EXIT -eq 0 ] && [ $STYLELINT_EXIT -eq 0 ] && [ $PURGE_EXIT -eq 0 ]; then
    echo -e "${GREEN}TOUT EST OK ! Votre code est prêt.${NC}"
    exit 0
else
    echo -e "${RED}CERTAINES ÉTAPES ONT ÉCHOUÉ. Veuillez corriger les erreurs ci-dessus.${NC}"
    exit 1
fi
