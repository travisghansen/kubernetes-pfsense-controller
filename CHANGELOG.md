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