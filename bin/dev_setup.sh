#!/usr/bin/env bash

SCRIPT_BASEDIR=$(dirname "$0")


set -e
cd "${SCRIPT_BASEDIR}/.."

which php &> /dev/null || { echo 'ERROR: php not found in PATH'; exit 1; }
which composer &> /dev/null || { echo 'ERROR: composer not found in PATH'; exit 1; }

if [[ ! -f .env ]]; then
    cp .env.example .env
fi

composer install --no-interaction
