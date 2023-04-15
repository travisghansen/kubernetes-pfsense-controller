# v0.5.14

Released 2023-04-15

- more robust handling of the shared frontend templates

# v0.5.13

Released 2023-02-23

- remove metallb-isms allowing the `metallb` plugin to work with `kube-vip`, `metallb` with crds, etc

# v0.5.12

Released 2023-02-04

- allow multiple frontends with `haproxy-ingress-proxy` (see #17)
- allow setting a template for shared frontends on a per-ingress basis (see #19)
  - shared frontend names are now more unique so old names will be removed and new added

# v0.5.11

Released 2023-01-24

- cleanup deprecation notices

# v0.5.10

Released 2023-01-24

- container deps

# v0.5.9

Released 2023-01-24

- update base container to `php:8.2-cli-alpine`
- build additional platforms for container images
- new env vars to support self-signed ca certs
- bump composer deps
- CI updates

# v0.5.8

Released 2021-09-05

- properly escape regex values in `haproxy-ingress-proxy`

# v0.5.7

Released 2021-09-05

- support wildcard hosts in `haproxy-ingress-proxy`
- support empty hosts in `haproxy-ingress-proxy`
- more stringent acls in `haproxy-ingress-proxy` to follow spec more closely
- support `pathType` in `haproxy-ingress-proxy`

# v0.5.6

Released 2021-09-04

- support for sni based routing in `haproxy-ingress-proxy` when type is `https`
- handle more stringent type checks by php 8

# v0.5.5

Released 2021-08-01

- handle more stringent type checks by php 8

# v0.5.4

Released 2021-08-01

- handle more stringent type checks by php 8

# v0.5.3

Released 2021-08-01

- handle more stringent type checks by php 8

# v0.5.2

Released 2021-08-01

- update deploy assets to use modern `apiVersion`s
- handle more stringent type checks by php 8

# v0.5.1

Released 2021-07-31

- fix faulty null coalescing logic

# v0.5.0

Release 2021-07-31

- support php 8
- bump docker images to use php 8
- replace zend-xmlrpc with laminas-xmlrpc
- support kubernetes 1.22+ (ingresses v1)
- remove all references to `selfLink`
- support pfSense >= 2.5.2 due to updated xmlrcp method signatures [(link)](https://github.com/pfsense/pfsense/commit/4f26f187d8cc5028646e86fbb95ce91552d062c2)
- bump various composer packages

# v0.4.0

Release 2021-05-10

- support multiple hostnames for `pfsense-dns-services`
- bump composer packages
- build a helm chart

# v0.3.3

Released 2020-12-20

- multi-arch docker builds
- move from travis-ci to github actions
- created a corresponding chart repo to install with helm

# v0.3.0

Released 2020-05-21

- introduce annotations on ingresses to support fine-grained control over creating respective DNS/HAProxy assets

# v0.2.0

Released 2020-04-11

- use `->createList()` vs simple `->request()` throughout to support resource types that may have many resources
- fix multi-host issues with `pfsense-dns-haproxy-ingress-proxy` (see #8)
- Allow setting `CONTROLLER_NAME` and `CONTROLLER_NAMESPACE` `env` vars to support multiple deploys and deployments to
alternative namespaces (see #7)
- support the `frr` `bgp-implementation` for `metallb` plugin
- support custom `configMap` for `metallb` plugin
- better logic for `haproxy-declarative` to support service changes including being created **after** the `ConfigMap`
- support service port names (vs port number) in `haproxy-declarative` `ConfigMap`s

# v0.1.9

Released 2019-11-30

- better logic for restarting DNS services
- minor cleanups

# v0.1.8

Released 2019-10-22

- several HAProxy fixes
- introduce env variable `PFSENSE_DEBUG` to log all pfSense xmlrpc traffic
- more robust logging for failure scenarios
- k8s version awareness to prep for deprecations of several API endpoints