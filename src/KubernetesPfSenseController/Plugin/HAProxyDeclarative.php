<?php

namespace KubernetesPfSenseController\Plugin;

/**
 * Purpose of this plugin is to facilitate creating HAProxy frontends/backends as kubernetes resources.  Generally the
 * interface is a pass-through to the pfSense configuration structure with a couple niceties added to dynamically
 * maintain backend hosts based off of cluster nodes.
 *
 * Class HAProxyDeclarative
 * @package KubernetesPfSenseController\Plugin
 */
class HAProxyDeclarative extends PfSenseAbstract
{
    /**
     * Unique plugin ID
     */
    const PLUGIN_ID = 'haproxy-declarative';

    use CommonTrait;

    /**
     * Init the plugin
     *
     * @throws \Exception
     */
    public function init()
    {
        $controller = $this->getController();

        // initial load of nodes
        $nodes = $controller->getKubernetesClient()->request('/api/v1/nodes');
        $this->state['nodes'] = $nodes['items'];

        // watch for node changes
        $params = [
            'resourceVersion' => $nodes['metadata']['resourceVersion'],
        ];
        $watch = $controller->getKubernetesClient()->createWatch('/api/v1/watch/nodes', $params, $this->getNodeWatchCallback('nodes'));
        $this->addWatch($watch);

        // initial load of configmaps
        $params = [
            'labelSelector' => 'pfsense.org/type=declarative',
        ];
        $configmaps = $controller->getKubernetesClient()->request('/api/v1/configmaps', 'GET', $params);
        $this->state['configmaps'] = $configmaps['items'];

        // watch for configmap changes
        $params = [
            'labelSelector' => 'pfsense.org/type=declarative',
            'resourceVersion' => $configmaps['metadata']['resourceVersion'],
        ];
        $watch = $controller->getKubernetesClient()->createWatch('/api/v1/watch/configmaps', $params, $this->getWatchCallback('configmaps'));
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
     * Update pfSense state
     *
     * @throws \Exception
     * @return bool
     */
    public function doAction()
    {
        $resources = [];
        $managedBackendsPreSave = [];
        $managedFrontendsPreSave = [];
        foreach ($this->state['configmaps'] as $configmap) {
            foreach (yaml_parse($configmap['data']['data'])['resources'] as $resource) {
                $resources[$resource['type']][] = $this->expandResource($resource, $configmap);
                switch ($resource['type']) {
                    case 'backend':
                        $managedBackendsPreSave[$resource['definition']['name']] = [
                            'resource' => $this->getKubernetesResourceDetails($configmap),
                        ];
                        break;
                    case 'frontend':
                        $managedFrontendsPreSave[$resource['definition']['name']] = [
                            'resource' => $this->getKubernetesResourceDetails($configmap),
                        ];
                        break;
                }
            }
        }

        $haProxyConfig = HAProxyConfig::getInstalledPackagesConfigBlock($this->getController()->getRegistryItem('pfSenseClient'), 'haproxy');

        if (!empty($resources['backend'])) {
            foreach ($resources['backend'] as $backend) {
                $haProxyConfig->putBackend($backend);
            }
        }

        if (!empty($resources['frontend'])) {
            foreach ($resources['frontend'] as $frontend) {
                $haProxyConfig->putFrontend($frontend);
            }
        }

        // remove resources created by plugin but no longer needed
        $store = $this->getStore();
        $managedBackends = $store['managed_backends'];
        if (empty($managedBackends)) {
            $managedBackends = [];
        }

        $managedFrontends = $store['managed_frontends'];
        if (empty($managedFrontends)) {
            $managedFrontends = [];
        }

        // actually remove them from config
        $toDeleteBackends = array_diff(@array_keys($managedBackends), @array_keys($managedBackendsPreSave));
        foreach ($toDeleteBackends as $backendName) {
            $haProxyConfig->removeBackend($backendName);
        }

        $toDeleteFrontends = array_diff(@array_keys($managedFrontends), @array_keys($managedFrontendsPreSave));
        foreach ($toDeleteFrontends as $frontendName) {
            $haProxyConfig->removeFrontend($frontendName);
        }

        try {
            $haProxyConfig->save();
            $this->reloadHAProxy();

            // persist the new set of managed resources
            $store['managed_backends'] = $managedBackendsPreSave;
            $store['managed_frontends'] = $managedFrontendsPreSave;
            $this->saveStore($store);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Fill out static definitions with dynamic data from cluster
     *
     * @param $resource
     * @param $kubernetesResource
     * @return array
     * @throws \Exception
     */
    private function expandResource($resource, $kubernetesResource)
    {
        $controller = $this->getController();
        switch ($resource['type']) {
            case 'node-static':
                return $resource['definition'];
                break;
            case 'node-service':
                $serviceNamespace = ($resource['serviceNamespace']) ? $resource['serviceNamespace'] : $kubernetesResource['metadata']['namespace'];
                $serviceName = $resource['serviceName'];
                $servicePort = $resource['servicePort'];

                $service = $controller->getKubernetesClient()->request("/api/v1/namespaces/${serviceNamespace}/services/${serviceName}");
                $hosts = [];
                switch ($service['spec']['type']) {
                    case 'LoadBalancer':
                        $hosts[] = [
                            'name' => $service['metadata']['name'],
                            'address' => $service['status']['loadBalancer']['ingress'][0]['ip'],
                            'port' => $servicePort
                        ];
                        break;
                    case 'NodePort':
                        $port = null;
                        foreach ($service['spec']['ports'] as $item) {
                            if ($item['port'] == $servicePort) {
                                $port = $item['nodePort'];
                                break;
                            }
                        }

                        foreach ($this->state['nodes'] as $node) {
                            $hosts[] = [
                                'name' => $node['metadata']['name'],
                                'address' => KubernetesUtils::getNodeIp($node),
                                'port' => $port
                            ];
                        }
                        break;
                    default:

                        break;
                }

                $servers = [];
                foreach ($hosts as $host) {
                    $servers[] = array_merge($resource['definition'], $host);
                }

                return $servers;
                break;
            case 'backend':
                // prep backend struct
                $expanded_backend = $resource['definition'];
                $expanded_backend['ha_servers'] = [];

                $servers = [];
                foreach ($resource['ha_servers'] as $server) {
                    if ($server['type'] == 'node-static') {
                        $servers[] = $this->expandResource($server, $kubernetesResource);
                    }

                    if ($server['type'] == 'node-service') {
                        $servers = array_merge($servers, $this->expandResource($server, $kubernetesResource));
                    }
                }

                foreach ($servers as $server) {
                    $expanded_backend['ha_servers']['item'][] = $server;
                }

                return $expanded_backend;
                break;
            case 'frontend':
                return $resource['definition'];
                break;
        }
    }
}
