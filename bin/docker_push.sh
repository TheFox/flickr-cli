#!/usr/bin/env bash

# Tags the existing Docker image and pushes the image to the Hub.

# Example usage, to tag Version 1.2.3:
# ./bin/docker_push.sh 1 1.2 1.2.3

# Example usage, to tag Version 1.2.4-dev.1:
# ./bin/docker_push.sh dev 1.2.4-dev 1.2.4-dev.1

DATE=$(date +"%Y%m%d_%H%M%S")
SCRIPT_BASEDIR=$(dirname "$0")
versions=$*


set -e
which docker &> /dev/null || { echo 'ERROR: docker not found in PATH'; exit 1; }

cd "${SCRIPT_BASEDIR}/.."
source ./.env

if [[ -z "$versions" ]]; then
    echo 'ERROR: no version given'
    exit 1
fi

for version in $versions ; do
    echo "Tag version: $version"

    # Tag
    docker tag ${IMAGE_NAME}:latest ${IMAGE_NAME}:${version}

    # Push Tags
    docker push ${IMAGE_NAME}:latest
    docker push ${IMAGE_NAME}:${version}
done
