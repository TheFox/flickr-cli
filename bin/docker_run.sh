#!/usr/bin/env bash

SCRIPT_BASEDIR=$(dirname "$0")


set -e
which docker &> /dev/null || { echo 'ERROR: docker not found in PATH'; exit 1; }

cd "${SCRIPT_BASEDIR}/.."
source ./.env

user_id=$(id -u)
user_gid=$(id -g)

set -x
docker run \
    --rm \
    --interactive \
    --tty \
    --name ${IMAGE_NAME_SHORT} \
    --hostname ${IMAGE_NAME_SHORT} \
    --user ${user_id}:${user_gid} \
    --volume "$PWD":/mnt \
    --volume flickrcli:/data \
    ${IMAGE_NAME} $*
