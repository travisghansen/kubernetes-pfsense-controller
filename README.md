![Image](https://img.shields.io/docker/pulls/travisghansen/kubernetes-pfsense-controller.svg)
![Image](https://img.shields.io/github/actions/workflow/status/travisghansen/kubernetes-pfsense-controller/main.yml?branch=master&style=flat-square)

# Intro
[kubernetes-pfsense-controller (kpc)](https://github.com/travisghansen/kubernetes-pfsense-controller) works hard to keep
[pfSense](https://www.pfsense.org/) and [Kubernetes](https://kubernetes.io/) in sync and harmony.  The primary focus is
to facilitate a first-class Kubernetes cluster by integrating and/or implementing features that generally do not come
with bare-metal installation(s).

This is generally achieved using the standard Kubernetes API along with the xmlrpc API for pfSense.  Speaking broadly
the Kubernetes API is `watch`ed and appropriate updates are sent to pfSense (`config.xml`) via xmlrpc calls along with
appropriate reload/restart/update/sync actions to apply changes.

Please note, this controller is not designed to run multiple instances simultaneously (ie: do NOT crank up the replicas).

Disclaimer: this is new software bound to have bugs.  Please make a backup before using it as it may eat your
configuration.  Having said that, all known code paths appear to be solid and working without issue.  If you find a bug,
please report it! 

Updated disclaimer: this software is no longer very new, but is still bound to have bugs. Continue to make backups as
appropriate :) Having said that, it's been used for multiple years now on several systems and has yet to do anything
evil.

# Installation

Various files are available in the `deploy` directory of the project, alter to your needs and `kubectl apply`.

Alternatively, a helm repository is provided for convenience:

```
helm repo add kubernetes-pfsense-controller https://travisghansen.github.io/kubernetes-pfsense-controller-chart/
helm repo update

# create your own values.yaml file and edit as appropriate
# https://github.com/travisghansen/kubernetes-pfsense-controller-chart/blob/master/stable/kubernetes-pfsense-controller/values.yaml
helm upgrade \
--install \
--create-namespace \
--namespace kpc \
--values values.yaml \
kpc-primary \
kubernetes-pfsense-controller/kubernetes-pfsense-controller
```

## Support Matrix

Generally speaking `kpc` tracks the most recent versions of both kubernetes and pfSense. Having said that reasonable
attempts will be made to support older versions of both.

`kpc` currently works with any `2.4+` (known working up to `2.5.2`) version of pfSense and probably very old kubernetes
versions (known working up to `1.22`).

# Plugins
The controller is comprised of several plugins that are enabled/disabled/configured via a Kubernetes ConfigMap.  Details
about each plugin follows below.

## metallb
[MetalLB](https://metallb.universe.tf/) implements `LoadBalancer` type `Service`s in Kubernetes.  This is done via any
combination of Layer2 or BGP type configurations.  Layer2 requires no integration with pfSense, however, if you want to
leverage the BGP implementation you need a BGP server along with neighbor configuration.  `kpc` *dynamically* updates
bgp neighbors for you in pfSense by continually monitoring cluster `Node`s.

While this plugin is *named* `metallb` it does not **require** MetalLB to be installed or in use. It can be used with
`kube-vip` or any other service that requires BGP peers/neighbors.

The plugin assumes you've already installed openbgp or frr and configured it as well as created a `group` to use with
MetalLB.

```yaml
      metallb:
        enabled: true
        nodeLabelSelector:
        nodeFieldSelector:
        # pick 1 implementation
        # bgp-implementation: openbgp
        bgp-implementation: frr
        options:
          frr:
            template:
              peergroup: metallb

          openbgp:
            template:
              md5sigkey:
              md5sigpass:
              groupname: metallb
              row:
                - parameters: announce all
                  parmvalue:
```

## haproxy-declarative
`haproxy-declarative` plugin allows you to declaratively create HAProxy frontend/backend definitions as `ConfigMap`
resources in the cluster.  When declaring backends however, the pool of servers can/will be dynamically created/updated
based on cluster nodes.  See [declarative-example.yaml](examples/declarative-example.yaml) for an example.

```yaml
      haproxy-declarative:
        enabled: true
```

## haproxy-ingress-proxy
`haproxy-ingress-proxy` plugin allows you to mirror cluster ingress rules handled by an ingress controller to HAProxy
running on pfSense.  If you run pfSense on the network edge with non-cluster services already running, you now can
dynamically inject new rules to route traffic into your cluster while simultaneously running non-cluster services.

To achieve this goal, new 'shared' HAProxy frontends are created and attached to an **existing** HAProxy frontend.  Each
created frontend should also set an existing backend.  Note that existing frontend(s)/backend(s) can be created manually
or using the `haproxy-declarative` plugin.

When creating the parent frontend(s) please note that the selected type should be `http / https(offloading)` to fully
support the feature. If type `ssl / https(TCP mode)` is selected (`SSL Offloading` may be selected or not in the
`External address` table) `sni` is used for routing logic and **CANNOT** support path-based logic which implies a 1:1
mapping between `host` entries and backing `service`s. Type `tcp` will not work and any `Ingress` resources that would
be bound to a frontend of this type are ignored.

Combined with `haproxy-declarative` you can create a dynamic backend service (ie: your ingress controller) and
subsequently dynamic frontend services based off of cluster ingresses.  This is generally helpful when you cannot or do
not for whatever reason create wildcard frontend(s) to handle incoming traffic in HAProxy on pfSense.

Optionally, on the ingress resources you can set the following annotations: `haproxy-ingress-proxy.pfsense.org/frontend`
and `haproxy-ingress-proxy.pfsense.org/backend` to respectively set the frontend and backend to override the defaults.

In advanced scenarios it is possible to provide a template definition of the shared frontend using the
`haproxy-ingress-proxy.pfsense.org/frontendDefinitionTemplate` annotation (see
https://github.com/travisghansen/kubernetes-pfsense-controller/issues/19#issuecomment-1416576678).

```yaml
      haproxy-ingress-proxy:
        enabled: true
        ingressLabelSelector:
        ingressFieldSelector:
        # works in conjunction with the ingress annotation 'haproxy-ingress-proxy.pfsense.org/enabled'
        # if defaultEnabled is empty or true, you can disable specific ingresses by setting the annotation to false
        # if defaultEnabled is false, you can enable specific ingresses by setting the annotation to true
        defaultEnabled: true
        # can optionally be comma-separated list if you want the same ingress to be served by multiple frontends
        defaultFrontend: http-80
        defaultBackend: traefik
        #allowedHostRegex: "/.*/"
```

## DNS Helpers
`kpc` provides various options to manage DNS entries in pfSense based on cluster state.  Note that these options can be
used in place of or in conjunction with [external-dns](https://github.com/kubernetes-incubator/external-dns) to support
powerful setups/combinations.

### pfsense-dns-services
`pfsense-dns-services` watches for services of type `LoadBalancer` that have the annotation `dns.pfsense.org/hostname`
with the value of the desired hostname (optionally you may specifiy a comma-separated list of hostnames).  `kpc` will
create the DNS entry in unbound/dnsmasq.  Note that to actually get  an IP on these services you'll likely need
`MetalLB` deployed in the cluster (regardless of the `metallb` plugin running or not).

```yaml
      pfsense-dns-services:
        enabled: true
        serviceLabelSelector:
        serviceFieldSelector:
        #allowedHostRegex: "/.*/"
        dnsBackends:
          dnsmasq:
            enabled: true
          unbound:
            enabled: true
```

### pfsense-dns-ingresses
`pfsense-dns-ingresses` watches ingresses and automatically creates DNS entries in unbound/dnsmasq. This requires proper
support from the ingress controller to set IPs on the ingress resources.

```yaml
      pfsense-dns-ingresses:
        enabled: true
        ingressLabelSelector:
        ingressFieldSelector:
        # works in conjunction with the ingress annotation 'dns.pfsense.org/enabled'
        # if defaultEnabled is empty or true, you can disable specific ingresses by setting the annotation to false
        # if defaultEnabled is false, you can enable specific ingresses by setting the annotation to true
        defaultEnabled: true
        #allowedHostRegex: "/.*/"
        dnsBackends:
          dnsmasq:
            enabled: true
          unbound:
            enabled: true
```

### pfsense-dns-haproxy-ingress-proxy
`pfsense-dns-haproxy-ingress-proxy` monitors the HAProxy rules created by the `haproxy-ingress-proxy` plugin and creates
host aliases for each entry.  To do so you create an arbitrary host in unbound/dnsmasq (something like
`<frontend name>.k8s`) and bind that host to the frontend through the config option `frontends.<frontend name>`.  Any
proxy rules created for that frontend will now automatically get added as aliases to the configured `hostname`.  Make
sure the static `hostname` created in your DNS service of choice points to the/an IP bound to the corresponding
`frontend`.

```yaml
      pfsense-dns-haproxy-ingress-proxy:
        enabled: true
        # NOTE: this regex is in *addition* to the regex applied to the haproxy-ingress-proxy plugin
        #allowedHostRegex: "/.*/"
        dnsBackends:
          dnsmasq:
            enabled: true
          unbound:
            enabled: true
        frontends:
          http-80:
            hostname: http-80.k8s
          primary_frontend_name2:
            hostname: primary_frontend_name2.k8s
```

# Notes
`regex` parameters are passed through php's `preg_match()` method, you can test your syntax using that.  Also note that
if you want to specify a regex ending (`$`), you must escape it in yaml as 2 `$`
(ie: `#allowedHostRegex: "/.example.com$$/"`).

`kpc` stores it's stateful data in the cluster as a ConfigMap (kube-system.kubernetes-pfsense-controller-store by
default).  You can review the data there to gain understanding into what the controller is managing.

You may need/want to bump up the `webConfigurator` setting for `Max Processes` to ensure enough simultaneous connections
can be established.  Each `kpc` instance will only require 1 process (ie: access to the API is serialized by `kpc`).

## Links
 * https://medium.com/@ipuustin/using-metallb-as-kubernetes-load-balancer-with-ubiquiti-edgerouter-7ff680e9dca3
 * https://miek.nl/2017/december/16/a-k8s-lb-using-arp/

# TODO
 1. base64 advanced fields (haproxy)
 1. taint haproxy config so it shows 'apply' button in interface?
 1. _index and id management
 1. ssl certs name/serial
 1. build docker images
 1. create manifests
 1. ensure pfsync items are pushed as appropriate
 1. perform config rollbacks when appropriate?
 1. validate configuration(s) to ensure proper schema

# Development

## check store values

```
kubectl -n kube-system get configmaps kubernetes-pfsense-controller-store -o json | jq -crM '.data."haproxy-declarative"' | jq .
kubectl -n kube-system get configmaps kubernetes-pfsense-controller-store -o json | jq -crM '.data."metallb"' | jq .
...
```

## HAProxy
XML config structure (note that `ha_backends` is actually frontends...it's badly named):
```yaml
haproxy
   ha_backends
     item
     item
     ...
   ha_pools
     item
       ha_servers
         item
         item
         ...
     item
     ...
```

### Links
 * https://github.com/pfsense/FreeBSD-ports/blob/devel/net/pfSense-pkg-haproxy-devel/files/usr/local/pkg/haproxy/haproxy.inc

## Links
 * https://github.com/pfsense/pfsense/blob/master/src/usr/local/www/xmlrpc.php
 * https://clouddocs.f5.com/products/connectors/k8s-bigip-ctlr/v1.5/
 * https://github.com/schematicon/validator-php
 * https://kubernetes.io/docs/concepts/overview/working-with-objects/kubernetes-objects/
 * https://kubernetes.io/docs/concepts/overview/working-with-objects/field-selectors/
 * https://kubernetes.io/docs/concepts/overview/working-with-objects/labels/
 * https://github.com/MacFJA/PharBuilder
