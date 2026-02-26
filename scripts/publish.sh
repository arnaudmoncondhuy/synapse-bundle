#!/bin/bash

# Synapse Bundle - Publication Script
# Usage: ./scripts/publish.sh <version> [create-tags]

VERSION="${1:-0.260226}"
CREATE_TAGS="${2:-false}"

PACKAGES=("core" "admin" "chat")
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

echo "🚀 Synapse Bundle - Publication Script"
echo "========================================"
echo "Version: $VERSION"
echo "Create tags: $CREATE_TAGS"
echo ""

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# ============================================
# 1. Vérifier la structure
# ============================================

echo "📋 Étape 1 : Vérifier la structure..."

for PACKAGE in "${PACKAGES[@]}"; do
    PACKAGE_DIR="$ROOT_DIR/packages/$PACKAGE"

    if [ ! -d "$PACKAGE_DIR" ]; then
        echo -e "${RED}✗ Dossier $PACKAGE not found${NC}"
        exit 1
    fi

    # Vérifier les fichiers critiques
    for FILE in "composer.json" "LICENSE" "README.md" "src/${PACKAGE^}Bundle.php"; do
        if [ "$FILE" = "src/${PACKAGE^}Bundle.php" ]; then
            # Chercher le bundle (peut être différent selon le package)
            case "$PACKAGE" in
                "core") BUNDLE_FILE="src/SynapseCoreBundle.php" ;;
                "admin") BUNDLE_FILE="src/SynapseAdminBundle.php" ;;
                "chat") BUNDLE_FILE="src/SynapseChatBundle.php" ;;
            esac
            if [ ! -f "$PACKAGE_DIR/$BUNDLE_FILE" ]; then
                echo -e "${YELLOW}⚠ $PACKAGE : $BUNDLE_FILE not found${NC}"
                echo "  → Will create it during validation"
            fi
        elif [ ! -f "$PACKAGE_DIR/$FILE" ]; then
            echo -e "${RED}✗ $PACKAGE : $FILE not found${NC}"
            exit 1
        fi
    done

    echo -e "${GREEN}✓ $PACKAGE${NC} structure OK"
done

echo ""

# ============================================
# 2. Valider composer.json
# ============================================

echo "🔍 Étape 2 : Valider composer.json..."

for PACKAGE in "${PACKAGES[@]}"; do
    PACKAGE_DIR="$ROOT_DIR/packages/$PACKAGE"

    if ! cd "$PACKAGE_DIR" && composer validate --quiet; then
        echo -e "${RED}✗ $PACKAGE : composer.json invalid${NC}"
        exit 1
    fi

    echo -e "${GREEN}✓ $PACKAGE${NC} composer.json valid"
done

cd "$ROOT_DIR"
echo ""

# ============================================
# 3. Vérifier les dépendances
# ============================================

echo "🔗 Étape 3 : Vérifier les dépendances..."

# Core ne doit dépendre de rien d'autre
if grep -q "synapse-admin\|synapse-chat" packages/core/composer.json; then
    echo -e "${RED}✗ core ne doit pas dépendre de admin ou chat${NC}"
    exit 1
fi

# Admin doit dépendre de core
if ! grep -q "synapse-core" packages/admin/composer.json; then
    echo -e "${RED}✗ admin doit dépendre de core${NC}"
    exit 1
fi

# Chat doit dépendre de core
if ! grep -q "synapse-core" packages/chat/composer.json; then
    echo -e "${RED}✗ chat doit dépendre de core${NC}"
    exit 1
fi

echo -e "${GREEN}✓${NC} Dépendances OK"
echo ""

# ============================================
# 4. Optionnel : Créer les tags
# ============================================

if [ "$CREATE_TAGS" = "true" ] || [ "$CREATE_TAGS" = "yes" ]; then
    echo "🏷️  Étape 4 : Créer les tags git..."

    for PACKAGE in "${PACKAGES[@]}"; do
        TAG_NAME="packages/${PACKAGE}-${VERSION}"

        if git rev-parse "$TAG_NAME" >/dev/null 2>&1; then
            echo -e "${YELLOW}⚠ Tag $TAG_NAME already exists${NC}"
        else
            if git tag "$TAG_NAME"; then
                echo -e "${GREEN}✓${NC} Tag created: $TAG_NAME"
            else
                echo -e "${RED}✗ Failed to create tag: $TAG_NAME${NC}"
                exit 1
            fi
        fi
    done

    echo ""
    echo "🚀 Maintenant, faire un push :"
    echo "   git push origin --tags"
    echo ""
fi

# ============================================
# 5. Résumé
# ============================================

echo "✅ Pré-publication check complète !"
echo ""
echo "📌 Prochaines étapes :"
echo ""
echo "1. Enregistrer les packages sur Packagist (manuel) :"
echo "   → https://packagist.org/packages/submit"
echo ""
echo "   Package 1 : arnaudmoncondhuy/synapse-core"
echo "   Repository : https://github.com/arnaudmoncondhuy/synapse-bundle.git"
echo "   Subdirectory : packages/core"
echo ""
echo "   Package 2 : arnaudmoncondhuy/synapse-admin"
echo "   Repository : https://github.com/arnaudmoncondhuy/synapse-bundle.git"
echo "   Subdirectory : packages/admin"
echo ""
echo "   Package 3 : arnaudmoncondhuy/synapse-chat"
echo "   Repository : https://github.com/arnaudmoncondhuy/synapse-bundle.git"
echo "   Subdirectory : packages/chat"
echo ""
echo "2. Ajouter les webhooks GitHub pour auto-update"
echo ""
echo "3. Tester localement :"
echo "   cd ../basile && composer update"
echo ""
echo "4. Vérifier sur Packagist :"
echo "   https://packagist.org/packages/arnaudmoncondhuy/synapse-core"
echo ""
