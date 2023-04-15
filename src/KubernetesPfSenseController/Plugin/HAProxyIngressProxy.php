<?php

namespace KubernetesPfSenseController\Plugin;

/**
 * Purpose of this plugin is to create a mirrored configuration of HAProxy running on pfSense to the provided ingress
 * controller already running (likely on NodePort or as a LoadBalancer) in the cluster.  The idea is that pfSense
 * running HAProxy receives traffic external to the cluster, forwards it to an existing ingress, which forwards it to
 * the appropriate pods.
 *
 * The following pre-requesites are assumed:
 *  - HAProxy Frontend (shared) already exists (configuration parameter)
 *  - HAProxy Backend already exists (configuration parameter) (this could be exposed using the Declarative plugin)
 *   - Backend should be running an exposed ingress already via NodePort or LoadBalancer (ie: nginx, haproxy, traefik)
 *
 * You may override the defaultFrontend and defaultBackend values on a per-ingress basis with annotations set on the
 * ingress:
 * haproxy-ingress-proxy.pfsense.org/frontend: test
 * haproxy-ingress-proxy.pfsense.org/backend: test
 *
 * Class HAProxyIngressProxy
 * @package KubernetesPfSenseController\Plugin
 */
class HAProxyIngressProxy extends PfSenseAbstract
{
    use CommonTrait;
    /**
     * Unique plugin ID
     */
    public const PLUGIN_ID = 'haproxy-ingress-proxy';

    /**
     * Annotation to override default frontend
     */
    public const FRONTEND_ANNOTATION_NAME = 'haproxy-ingress-proxy.pfsense.org/frontend';

    /**
     * Annotation to specific shared frontend template data
     */
    public const FRONTEND_DEFINITION_TEMPLATE_ANNOTATION_NAME = 'haproxy-ingress-proxy.pfsense.org/frontendDefinitionTemplate';

    /**
     * Annotation to override default backend
     */
    public const BACKEND_ANNOTATION_NAME = 'haproxy-ingress-proxy.pfsense.org/backend';

    /**
     * Annotation to override default enabled
     */
    public const ENABLED_ANNOTATION_NAME = 'haproxy-ingress-proxy.pfsense.org/enabled';

    /**
     * Init the plugin
     *
     * @throws \Exception
     */
    public function init()
    {
        $controller = $this->getController();
        $pluginConfig = $this->getConfig();
        $ingressLabelSelector = $pluginConfig['ingressLabelSelector'] ?? null;
        $ingressFieldSelector = $pluginConfig['ingressFieldSelector'] ?? null;

        // 1.20 will kill the old version
        // https://kubernetes.io/blog/2019/07/18/api-deprecations-in-1-16/
        $kubernetesMajorMinor = $controller->getKubernetesVersionMajorMinor();
        if (\Composer\Semver\Comparator::greaterThanOrEqualTo($kubernetesMajorMinor, '1.19')) {
            $ingressResourcePath = '/apis/networking.k8s.io/v1/ingresses';
            $ingressResourceWatchPath = '/apis/networking.k8s.io/v1/watch/ingresses';
        } elseif (\Composer\Semver\Comparator::greaterThanOrEqualTo($kubernetesMajorMinor, '1.14')) {
            $ingressResourcePath = '/apis/networking.k8s.io/v1beta1/ingresses';
            $ingressResourceWatchPath = '/apis/networking.k8s.io/v1beta1/watch/ingresses';
        } else {
            $ingressResourcePath = '/apis/extensions/v1beta1/ingresses';
            $ingressResourceWatchPath = '/apis/extensions/v1beta1/watch/ingresses';
        }

        // initial load of ingresses
        $params = [
            'labelSelector' => $ingressLabelSelector,
            'fieldSelector' => $ingressFieldSelector,
        ];
        $ingresses = $controller->getKubernetesClient()->createList($ingressResourcePath, $params)->get();
        $this->state['ingresses'] = $ingresses['items'];

        // watch for ingress changes
        $params = [
            'labelSelector' => $ingressLabelSelector,
            'fieldSelector' => $ingressFieldSelector,
            'resourceVersion' => $ingresses['metadata']['resourceVersion'],
        ];
        $watch = $controller->getKubernetesClient()->createWatch($ingressResourceWatchPath, $params, $this->getWatchCallback('ingresses'));
        $this->addWatch($watch);

        $this->delayedAction();
    }

    /**
     * Deinit the plugin
     */
    public function deinit()
    {
    }

    /**
     * Pre read watches
     */
    public function preReadWatches()
    {
    }

    /**
     * Post read watches
     */
    public function postReadWatches()
    {
    }

    /**
     * How long to wait for watches to settle
     *
     * @return int
     */
    public function getSettleTime()
    {
        return 10;
    }

    /**
     * Update pfSense state
     *
     * @return bool
     */
    public function doAction()
    {
        $pluginConfig = $this->getConfig();
        $haProxyConfig = HAProxyConfig::getInstalledPackagesConfigBlock($this->getController()->getRegistryItem('pfSenseClient'), 'haproxy');

        $resources = [];
        $frontendWarning = [];
        $backendWarning = [];
        foreach ($this->state['ingresses'] as $item) {
            $ingressNamespace = $item['metadata']['namespace'];
            $ingressName = $item['metadata']['name'];
            $frontendNameBase = $ingressNamespace . '-' . $ingressName . '-' . $this->getController()->getControllerId();

            if (KubernetesUtils::getResourceAnnotationExists($item, self::ENABLED_ANNOTATION_NAME)) {
                $ingressProxyEnabledAnnotationValue = KubernetesUtils::getResourceAnnotationValue($item, self::ENABLED_ANNOTATION_NAME);
                $ingressProxyEnabledAnnotationValue = strtolower($ingressProxyEnabledAnnotationValue);

                if (in_array($ingressProxyEnabledAnnotationValue, ["true", "1"])) {
                    $ingressProxyEnabled = true;
                } else {
                    $ingressProxyEnabled = false;
                }
            } else {
                if (key_exists('defaultEnabled', $pluginConfig)) {
                    $ingressProxyEnabled = (bool) $pluginConfig['defaultEnabled'];
                } else {
                    $ingressProxyEnabled = true;
                }
            }

            $frontendTemplate = [];
            if (KubernetesUtils::getResourceAnnotationExists($item, self::FRONTEND_DEFINITION_TEMPLATE_ANNOTATION_NAME)) {
                $frontendTemplateData = KubernetesUtils::getResourceAnnotationValue($item, self::FRONTEND_DEFINITION_TEMPLATE_ANNOTATION_NAME);
                if (!empty($frontendTemplateData)) {
                    $frontendTemplate = json_decode($frontendTemplateData, true);
                }
            }

            if (!$ingressProxyEnabled) {
                continue;
            }

            if (KubernetesUtils::getResourceAnnotationExists($item, self::FRONTEND_ANNOTATION_NAME)) {
                $primaryFrontendNames = KubernetesUtils::getResourceAnnotationValue($item, self::FRONTEND_ANNOTATION_NAME);
            } else {
                $primaryFrontendNames = $pluginConfig['defaultFrontend'];
            }

            $primaryFrontendNames = array_map('trim', explode(",", $primaryFrontendNames));

            if (KubernetesUtils::getResourceAnnotationExists($item, self::BACKEND_ANNOTATION_NAME)) {
                $backendName = KubernetesUtils::getResourceAnnotationValue($item, self::BACKEND_ANNOTATION_NAME);
            } else {
                $backendName = $pluginConfig['defaultBackend'];// use default or read annotation(s)
            }

            if (empty($primaryFrontendNames) || empty($backendName)) {
                $this->log('missing frontend or backend configuration: ' . $frontendNameBase);
                continue;
            };

            foreach ($primaryFrontendNames as $primaryFrontendName) {
                $frontendName = "{$primaryFrontendName}-{$frontendNameBase}";

                if (!$haProxyConfig->frontendExists($primaryFrontendName)) {
                    if (!in_array($primaryFrontendName, $frontendWarning)) {
                        //$frontendWarning[] = $primaryFrontendName;
                        $this->log("Frontend {$primaryFrontendName} must exist: {$frontendName}");
                    }
                    continue;
                }

                // get the type of the shared frontend
                // NOTE the below do NOT correlate 100% with what is shown on the 'type' column of the 'frontends' tab.
                // 'https' for example is actually http + ssl offloading checked
                /*
                <option value="http">http / https(offloading)</option>
                <option value="https">ssl / https(TCP mode)</option>
                <option value="tcp">tcp</option>
                */

                /**
                 * http - can do l7 rules such as headers, path, etc
                 * https - can only do sni rules
                 * tcp - cannot be used with this application
                 */
                $primaryFrontend = $haProxyConfig->getFrontend($primaryFrontendName);
                switch ($primaryFrontend['type']) {
                    case "http":
                    case "https":
                        // move along
                        break;
                    default:
                        $this->log("WARN haproxy frontend {$primaryFrontendName} has unsupported type: " . $primaryFrontend['type']);
                        continue 2;
                }

                if (!$haProxyConfig->backendExists($backendName)) {
                    if (!in_array($backendName, $backendWarning)) {
                        //$backendWarning[] = $backendName;
                        $this->log("Backend {$backendName} must exist: {$frontendName}");
                    }
                    continue;
                }

                // new frontend
                $frontend = $frontendTemplate;
                $frontend['name'] = $frontendName;
                $frontend['desc'] = 'created by kpc - do not edit';
                $frontend['status'] = 'active';
                $frontend['secondary'] = 'yes';
                $frontend['primary_frontend'] = $primaryFrontendName;

                // acls
                if (!is_array($frontend['ha_acls'])) {
                    $frontend['ha_acls'] = ['item' => []];
                }
                if (!is_array(($frontend['ha_acls']['item']))) {
                    $frontend['ha_acls']['item'] = [];
                }

                // actions
                if (!is_array($frontend['a_actionitems'])) {
                    $frontend['a_actionitems'] = ['item' => []];
                }
                if (!is_array($frontend['a_actionitems']['item'])) {
                    $frontend['a_actionitems']['item'] = [];
                }

                foreach ($item['spec']['rules'] as $ruleKey => $rule) {
                    $aclName = $frontend['name'] . '-rule-' . $ruleKey;
                    $host = $rule['host'] ?? '';
                    //$host = "*.{$host}"; // for testing purposes only
                    //$host = ""; // for testing purposes only
                    if (!$this->shouldCreateRule($rule)) {
                        continue;
                    }
                    //TODO: add certificate to primary_frontend via acme?

                    //acls with same name are OR'd by haproxy
                    foreach ($rule['http']['paths'] as $pathKey => $path) {
                        //$serviceNamespace = $ingressNamespace;
                        //$serviceName = $path['backend']['serviceName'];
                        //$servicePort = $path['backend']['servicePort'];

                        $path = $path['path'] ?? "";
                        if (empty($path)) {
                            $path = '/';
                        }

                        // new acl
                        $acl = [];
                        $acl['name'] = $aclName;
                        $acl['expression'] = 'custom';
                        // alter this based on shared frontend type
                        // if tcp/ssl then do sni-based rule
                        // https://stackoverflow.com/questions/33085240/haproxy-sni-vs-http-host-acl-check-performance
                        // req_ssl_sni (types https and tcp both equate to type tcp in the haproxy config), type tcp requires this variant
                        // ssl_fc_sni (this can be used only with type http)
                        switch ($primaryFrontend['type']) {
                            case "http":
                                // https://kubernetes.io/docs/concepts/services-networking/ingress/#hostname-wildcards
                                // https://serverfault.com/questions/388937/how-do-i-match-a-wildcard-host-in-acl-lists-in-haproxy
                                $hostACL = "";
                                if (substr($host, 0, 2) == "*.") {
                                    // hdr(host) -m reg -i ^[^\.]+\.example\.org$
                                    // hdr(host) -m reg -i ^[^\.]+\.example\.org(:[0-9]+)?$
                                    $hostACL = "hdr(host) -m reg -i ^[^\.]+" . str_replace([".", "-"], ["\.", "\-"], substr($host, 1)) . "(:[0-9]+)?$";
                                } else {
                                    $hostACL = "hdr(host) -m reg -i ^" . str_replace([".", "-"], ["\.", "\-"], $host) . "(:[0-9]+)?$";
                                }

                                // https://kubernetes.io/docs/concepts/services-networking/ingress/#path-types
                                // https://www.haproxy.com/documentation/hapee/latest/configuration/acls/syntax/
                                $pathType = $path['pathType'] ?? null;
                                $pathACL = "";
                                switch ($pathType) {
                                    case "Exact":
                                        /**
                                         * Matches the URL path exactly and with case sensitivity.
                                         */
                                        $pathACL = "path -m str {$path}";
                                        break;
                                    case "Prefix":
                                        /**
                                         * Matches based on a URL path prefix split by /.
                                         * Matching is case sensitive and done on a path element by element basis.
                                         * A path element refers to the list of labels in the path split by the / separator.
                                         * A request is a match for path p if every p is an element-wise prefix of p of the request path.
                                         */
                                        $pathACL = "path -m beg {$path}";
                                        break;
                                    case "ImplementationSpecific":
                                        /**
                                         * With this path type, matching is up to the IngressClass.
                                         * Implementations can treat this as a separate pathType or treat it identically to Prefix or Exact path types.
                                         */
                                        $pathACL = "path -m beg {$path}";
                                        break;
                                    default:
                                        $pathACL = "path -m beg {$path}";
                                        break;
                                }

                                if (empty($host)) {
                                    $hostACL = "";
                                }
                                $acl['value'] = trim("{$hostACL} {$pathACL}");
                                $frontend['ha_acls']['item'][] = $acl;
                                break;
                            case "https":
                                $this->log("WARN unexpected behavior may occur when using a shared frontend of type https, path-based routing will not work");

                                // https://kubernetes.io/docs/concepts/services-networking/ingress/#hostname-wildcards
                                // https://serverfault.com/questions/388937/how-do-i-match-a-wildcard-host-in-acl-lists-in-haproxy
                                $hostACL = "";
                                if (substr($host, 0, 2) == "*.") {
                                    // hdr(host) -m reg -i ^[^\.]+\.example\.org$
                                    // hdr(host) -m reg -i ^[^\.]+\.example\.org(:[0-9]+)?$
                                    // sni should never have the port on the end as the host header may have
                                    $hostACL = "req_ssl_sni -m reg -i ^[^\.]+" . str_replace([".", "-"], ["\.", "\-"], substr($host, 1));
                                } else {
                                    $hostACL = "req_ssl_sni -m str -i {$host}"; // exact match case-insensitive
                                }

                                if (empty($host)) {
                                    $hostACL = "";
                                    $this->log("WARN cannot create rule for {$frontendName} because host is required for primary frontends of type: " . $primaryFrontend['type']);
                                    continue 3;
                                }
                                $acl['value'] = trim("{$hostACL}");
                                $frontend['ha_acls']['item'][] = $acl;
                                break;
                            default:
                                // should never get here based on checks above, but just in case
                                $this->log("WARN haproxy frontend {$primaryFrontendName} has unsupported type: " . $primaryFrontend['type']);
                                continue 3;
                                break;
                        }
                    }

                    // new action (tied to acl)
                    $action = [];
                    $action['action'] = 'use_backend';
                    $action['use_backendbackend'] = $backendName;
                    $action['acl'] = $acl['name'];

                    // add action
                    $frontend['a_actionitems']['item'][] = $action;
                }

                ksort($frontend);

                // only create frontend if we have any actions
                if (count($frontend['a_actionitems']['item']) > 0) {
                    // add new frontend to list of resources
                    $frontend['_resource'] = $item;
                    $resources['frontend'][] = $frontend;
                } else {
                    //$this->log('no rules for frontend '. $frontend['name'].' ignoring');
                }
            }
        }

        //TODO: create certs first via ACME?

        // update config with new/updated frontends
        $managedFrontendsPreSave = [];
        $managedFrontendNamesPreSave = [];
        if (!empty($resources['frontend'])) {
            foreach ($resources['frontend'] as &$frontend) {
                // keep track of what we will manage
                $managedFrontendNamesPreSave[] = $frontend['name'];
                $managedFrontendsPreSave[$frontend['name']] = [
                    'resource' => $this->getKubernetesResourceDetails($frontend['_resource']),
                    'acls' => $frontend['ha_acls']['item'],
                ];
                unset($frontend['_resource']);
                if (!$haProxyConfig->frontendExists($frontend['name'])) {
                    $this->log('creating frontend: '.$frontend['name']);
                }
                $haProxyConfig->putFrontend($frontend);
            }
        }

        // remove frontends created by plugin but no longer needed
        $store = $this->getStore();
        if (empty($store)) {
            $store = [];
        }

        $store['managed_frontends'] = $store['managed_frontends'] ?? [];

        // get what we currently manage
        $managedFrontendNames = @array_keys($store['managed_frontends']);
        if (empty($managedFrontendNames)) {
            $managedFrontendNames = [];
        }

        // actually remove them from config
        $toDeleteFrontends = array_diff($managedFrontendNames, $managedFrontendNamesPreSave);
        foreach ($toDeleteFrontends as $frontendName) {
            $this->log("removing frontend no longer needed: {$frontendName}");
            $haProxyConfig->removeFrontend($frontendName);
        }

        try {
            $this->savePfSenseConfigBlock($haProxyConfig);
            $this->reloadHAProxy();

            // persist the new set of managed frontends
            $store['managed_frontends'] = $managedFrontendsPreSave;
            $this->saveStore($store);

            return true;
        } catch (\Exception $e) {
            $this->log('failed update/reload: '.$e->getMessage().' ('.$e->getCode().')');
            return false;
        }
    }

    /**
     * If rule should be created
     *
     * @param $rule
     * @return bool
     */
    private function shouldCreateRule($rule)
    {
        $hostName = $rule['host'] ?? '';
        $pluginConfig = $this->getConfig();
        if (!empty($pluginConfig['allowedHostRegex'])) {
            $allowed = @preg_match($pluginConfig['allowedHostRegex'], $hostName);
            if ($allowed !== 1) {
                return false;
            }
        }

        return true;
    }
}
