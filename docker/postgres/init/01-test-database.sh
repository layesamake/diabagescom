#!/bin/sh
# Crée la base dédiée aux tests automatisés (local uniquement).
set -eu
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<SQL
CREATE DATABASE "${DIABA_TEST_DATABASE}" OWNER "${POSTGRES_USER}";
SQL
