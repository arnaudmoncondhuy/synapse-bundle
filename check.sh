#!/bin/bash
# Point d'entrée — délègue à qa/check.sh
exec "$(dirname "${BASH_SOURCE[0]}")/qa/check.sh" "$@"
