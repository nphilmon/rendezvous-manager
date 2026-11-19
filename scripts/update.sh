#!/usr/bin/env bash
# Mise à jour d'une installation existante, avec maintenance, sauvegarde préalable, migrations et contrôle final.
# Usage : scripts/update.sh [--branch=main] [--no-backup]
# Variables : PHP_BIN (défaut : php). En cas d'échec, la maintenance reste active : corrigez, ou revenez en arrière (voir le message), puis
# levez-la avec « rm storage/maintenance.lock ».
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
PHP="${PHP_BIN:-php}"
BRANCH=""
BACKUP=1
for a in "$@"; do
  case "$a" in
    --branch=*) BRANCH="${a#--branch=}";;
    --no-backup) BACKUP=0;;
    *) echo "Option inconnue : $a" >&2; exit 2;;
  esac
done
[ -f storage/install.lock ] || { echo "Installation non terminée (storage/install.lock absent) : rien à mettre à jour." >&2; exit 2; }
git diff --quiet && git diff --cached --quiet || { echo "Des fichiers du dépôt ont été modifiés localement : mise à jour annulée (git status)." >&2; exit 2; }

BEFORE="$(git rev-parse HEAD)"
LOCK=storage/maintenance.lock
OK=0
finish() {
  if [ "$OK" -eq 1 ]; then rm -f "$LOCK"; else
    echo >&2
    echo "ÉCHEC : la maintenance reste ACTIVE ($LOCK). Pour revenir en arrière :" >&2
    echo "  git checkout $BEFORE && $PHP scripts/restore.php <dernière sauvegarde> --yes && rm $LOCK" >&2
  fi
}
trap finish EXIT
touch "$LOCK"
echo "== Maintenance activée"

if [ "$BACKUP" -eq 1 ]; then
  echo "== Sauvegarde de la base"
  "$PHP" workers/backup_worker.php
fi

echo "== Récupération du code"
git fetch --quiet origin
if [ -n "$BRANCH" ]; then git checkout --quiet "$BRANCH"; fi
git pull --ff-only --quiet
AFTER="$(git rev-parse HEAD)"
if [ "$BEFORE" = "$AFTER" ]; then echo "   déjà à jour ($AFTER)"; else git --no-pager log --oneline "$BEFORE..$AFTER"; fi

echo "== Migrations"
"$PHP" scripts/migrate.php

echo "== Contrôles"
"$PHP" scripts/deploy_check.php
OK=1
echo "== Mise à jour terminée (ancienne version : $BEFORE). Maintenance levée."
