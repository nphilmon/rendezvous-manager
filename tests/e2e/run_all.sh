#!/usr/bin/env bash
# Lance tous les tests navigateur (Playwright) l'un après l'autre : base de test repeuplée avant chaque script, serveur PHP intégré,
# sauvegardes dans un dossier temporaire, nettoyage garanti (verrou d'installation retiré, serveur arrêté).
#
# Prérequis : PHP 8.3, Node + Playwright (NODE_PATH=$(npm root -g)), une base MariaDB/MySQL dédiée dont le nom se termine par _test.
# Usage :  TEST_DB_NAME=rendezvous_manager_test TEST_DB_USER=… TEST_DB_PASSWORD=… tests/e2e/run_all.sh [script.e2e.js …]
set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"
: "${TEST_DB_NAME:?TEST_DB_NAME requis (se terminant par _test)}" "${TEST_DB_USER:?TEST_DB_USER requis}"
case "$TEST_DB_NAME" in *_test) ;; *) echo "Refus : la base doit se terminer par _test." >&2; exit 2;; esac
PORT="${E2E_PORT:-8099}"
export DB_HOST="${TEST_DB_HOST:-127.0.0.1}" DB_PORT="${TEST_DB_PORT:-3306}" DB_NAME="$TEST_DB_NAME" DB_USER="$TEST_DB_USER" DB_PASSWORD="${TEST_DB_PASSWORD:-}"
export DB_SSL_MODE=disable COOKIE_SECURE=false SYNC_INTERVAL=2 APP_ENV=testing BASE_URL="http://127.0.0.1:$PORT"
export NODE_PATH="${NODE_PATH:-$(npm root -g 2>/dev/null)}"
export BACKUP_DIR="$(mktemp -d)"
LOCK="$ROOT/storage/install.lock"
if [ -e "$LOCK" ]; then echo "Refus : $LOCK existe déjà (installation réelle ?)." >&2; exit 2; fi

SERVER_PID=""
cleanup() { [ -n "$SERVER_PID" ] && kill "$SERVER_PID" 2>/dev/null; rm -f "$LOCK"; rm -rf "$BACKUP_DIR"; }
trap cleanup EXIT INT TERM
touch "$LOCK"
PHP_CLI_SERVER_WORKERS="${E2E_WORKERS:-4}" php -S "127.0.0.1:$PORT" -t public public/index.php >"${TMPDIR:-/tmp}/rvm-e2e-server.log" 2>&1 &
SERVER_PID=$!
for _ in $(seq 1 50); do curl -fs -o /dev/null "$BASE_URL/login" && break; sleep 0.2; done
curl -fs -o /dev/null "$BASE_URL/login" || { echo "Serveur de test injoignable (voir ${TMPDIR:-/tmp}/rvm-e2e-server.log)." >&2; exit 2; }

if [ "$#" -gt 0 ]; then SCRIPTS=("$@"); else SCRIPTS=(tests/e2e/*.e2e.js); fi
pass=0; fail=0; failed=()
for s in "${SCRIPTS[@]}"; do
  php tests/e2e/seed.php >/dev/null 2>&1 || { echo "Échec du peuplement de la base de test." >&2; exit 2; }
  echo "=== $s"
  out="$(timeout "${E2E_TIMEOUT:-240}" node "$s" 2>&1)"; code=$?
  echo "$out" | grep -E "FAIL|Error|rror:|waiting for|e2e.js:[0-9]+" | head -8
  ok=$(echo "$out" | grep -c "^  ok"); ko=$(echo "$out" | grep -c "^  FAIL")
  if [ "$code" -eq 0 ]; then pass=$((pass+1)); echo "    $ok vérifications réussies"; else fail=$((fail+1)); failed+=("$s"); echo "    ÉCHEC (code $code) : $ok réussies, $ko en échec"; fi
done
echo; echo "Scripts navigateur : $pass réussis, $fail en échec"
[ "$fail" -eq 0 ] || { printf ' - %s\n' "${failed[@]}"; exit 1; }
