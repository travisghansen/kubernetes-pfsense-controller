#!/bin/bash

echo "$DOCKER_PASSWORD" | docker login -u "$DOCKER_USERNAME" --password-stdin
docker build --pull -t travisghansen/kubernetes-pfsense-controller:${TRAVIS_TAG} .
docker push travisghansen/kubernetes-pfsense-controller:${TRAVIS_TAG}
