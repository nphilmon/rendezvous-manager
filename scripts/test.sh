#!/usr/bin/env bash
# Lance toute la batterie de tests : unitaires, intégration et sécurité (php tests/run.php), puis, avec --e2e, les tests navigateur.
# Variables : TEST_DB_HOST, TEST_DB_PORT, TEST_DB_NAME (doit finir par _test), TEST_DB_USER, TEST_DB_PASSWORD.
# Sans TEST_DB_*, les tests qui ont besoin d'une base sont ignorés (et signalés comme tels).
set -u
cd "$(dirname "${BASH_SOURCE[0]}")/.."
php tests/run.php "${FILTER:-}"; code=$?
if [ "${1:-}" = "--e2e" ]; then
  tests/e2e/run_all.sh; e=$?
  [ "$code" -eq 0 ] && code=$e
fi
exit "$code"
