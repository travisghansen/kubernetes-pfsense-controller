#!/bin/bash

echo "$DOCKER_PASSWORD" | docker login -u "$DOCKER_USERNAME" --password-stdin

if [[ -n "${TRAVIS_TAG}" ]];then
	docker build --pull -t travisghansen/kubernetes-pfsense-controller:${TRAVIS_TAG} .
	docker push travisghansen/kubernetes-pfsense-controller:${TRAVIS_TAG}
elif [[ "${TRAVIS_BRANCH}" == "master" ]];then
	docker build --pull -t travisghansen/kubernetes-pfsense-controller:latest .
	docker push travisghansen/kubernetes-pfsense-controller:latest
else
	docker build --pull -t travisghansen/kubernetes-pfsense-controller:${TRAVIS_BRANCH} .
	docker push travisghansen/kubernetes-pfsense-controller:${TRAVIS_BRANCH}
fi
