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
    /**
     * Unique plugin ID
     */
    const PLUGIN_ID = 'haproxy-ingress-proxy';

    /**
     * Annotation to override default frontend
     */
    const FRONTEND_ANNOTATION_NAME = 'haproxy-ingress-proxy.pfsense.org/frontend';

    /**
     * Annotation to override default backend
     */
    const BACKEND_ANNOTATION_NAME = 'haproxy-ingress-proxy.pfsense.org/backend';

    use CommonTrait;

    /**
     * Init the plugin
     *
     * @throws \Exception
     */
    public function init()
    {
        $controller = $this->getController();
        $pluginConfig = $this->getConfig();
        $ingressLabelSelector = $pluginConfig['ingressLabelSelector'];
        $ingressFieldSelector = $pluginConfig['ingressFieldSelector'];

        // 1.20 will kill the old version
        // https://kubernetes.io/blog/2019/07/18/api-deprecations-in-1-16/
        $kubernetesMajorMinor = $controller->getKubernetesVersionMajorMinor();
        if (\Composer\Semver\Comparator::greaterThanOrEqualTo($kubernetesMajorMinor, '1.14')) {
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
        $ingresses = $controller->getKubernetesClient()->request($ingressResourcePath, 'GET', $params);
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
            $frontendName = $this->getController()->getControllerId().'-'.$ingressNamespace.'-'.$ingressName;

            if (KubernetesUtils::getResourceAnnotationExists($item, self::FRONTEND_ANNOTATION_NAME)) {
                $sharedFrontendName = KubernetesUtils::getResourceAnnotationValue($item, self::FRONTEND_ANNOTATION_NAME);
            } else {
                $sharedFrontendName = $pluginConfig['defaultFrontend'];
            }

            if (KubernetesUtils::getResourceAnnotationExists($item, self::BACKEND_ANNOTATION_NAME)) {
                $backendName = KubernetesUtils::getResourceAnnotationValue($item, self::BACKEND_ANNOTATION_NAME);
            } else {
                $backendName = $pluginConfig['defaultBackend'];// use default or read annotation(s)
            }

            if (empty($sharedFrontendName) || empty($backendName)) {
                $this->log('missing frontend or backend configuration: '.$frontendName);
                continue;
            }

            if (!$haProxyConfig->frontendExists($sharedFrontendName)) {
                if (!in_array($sharedFrontendName, $frontendWarning)) {
                    //$frontendWarning[] = $sharedFrontendName;
                    $this->log("Frontend ${sharedFrontendName} must exist: ${frontendName}");
                }
                continue;
            }

            if (!$haProxyConfig->backendExists($backendName)) {
                if (!in_array($backendName, $backendWarning)) {
                    //$backendWarning[] = $backendName;
                    $this->log("Backend ${backendName} must exist: $frontendName");
                }
                continue;
            }

            // new frontend
            $frontend = [];
            $frontend['name'] = $frontendName;
            $frontend['desc'] = 'created by kpc - do not edit';
            $frontend['status'] = 'active';
            $frontend['secondary'] = 'yes';
            $frontend['primary_frontend'] = $sharedFrontendName;
            $frontend['ha_acls'] = ['item' => []];
            $frontend['a_actionitems'] = ['item' => []];

            foreach ($item['spec']['rules'] as $ruleKey => $rule) {
                $aclName = $frontend['name'].'-rule-'.$ruleKey;
                $host = $rule['host'];
                if (!$this->shouldCreateRule($rule)) {
                    continue;
                }
                //TODO: add certificate to primary_frontend via acme?

                //acls with same name are OR'd by haproxy
                foreach ($rule['http']['paths'] as $pathKey => $path) {
                    //$serviceNamespace = $ingressNamespace;
                    //$serviceName = $path['backend']['serviceName'];
                    //$servicePort = $path['backend']['servicePort'];

                    $path = $path['path'];
                    if (empty($path)) {
                        $path = '/';
                    }

                    // new acl
                    $acl = [];
                    $acl['name'] = $aclName;
                    $acl['expression'] = 'custom';
                    $acl['value'] = "hdr(host) -i ${host} path_beg -i ${path}";

                    $frontend['ha_acls']['item'][] = $acl;
                }

                // new action (tied to acl)
                $action = [];
                $action['action'] = 'use_backend';
                $action['use_backendbackend'] = $backendName;
                $action['acl'] = $acl['name'];

                // add action
                $frontend['a_actionitems']['item'][] = $action;
            }

            // only create frontend if we have any actions
            if (count($frontend['a_actionitems']['item']) > 0) {
                // add new frontend to list of resources
                $frontend['_resource'] = $item;
                $resources['frontend'][] = $frontend;
            } else {
                //$this->log('no rules for frontend '. $frontend['name'].' ignoring');
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

        // get what we currently manage
        $managedFrontendNames = @array_keys($store['managed_frontends']);
        if (empty($managedFrontendNames)) {
            $managedFrontendNames = [];
        }

        // actually remove them from config
        $toDeleteFrontends = array_diff($managedFrontendNames, $managedFrontendNamesPreSave);
        foreach ($toDeleteFrontends as $frontendName) {
            $this->log("removing frontend no longer needed: ${frontendName}");
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
        $hostName = $rule['host'];
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
