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
    use CommonTrait;
    /**
     * Unique plugin ID
     */
    public const PLUGIN_ID = 'haproxy-declarative';

    /**
     * Init the plugin
     *
     * @throws \Exception
     */
    public function init()
    {
        $controller = $this->getController();

        // initial load of nodes
        $nodes = $controller->getKubernetesClient()->createList('/api/v1/nodes')->get();
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
        $configmaps = $controller->getKubernetesClient()->createList('/api/v1/configmaps', $params)->get();
        $this->state['configmaps'] = $configmaps['items'];

        // watch for configmap changes
        $params = [
            'labelSelector' => 'pfsense.org/type=declarative',
            'resourceVersion' => $configmaps['metadata']['resourceVersion'],
        ];
        $watch = $controller->getKubernetesClient()->createWatch('/api/v1/watch/configmaps', $params, $this->getWatchCallback('configmaps'));
        $this->addWatch($watch);

        // watch for service changes
        $services = $controller->getKubernetesClient()->createList('/api/v1/services')->get();
        $this->state['services'] = $services['items'];

        $params = [];
        $watch = $controller->getKubernetesClient()->createWatch('/api/v1/watch/services', $params, $this->getWatchCallback('services'));
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
        if (empty($store)) {
            $store = [];
        }

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
            $this->savePfSenseConfigBlock($haProxyConfig);
            $this->reloadHAProxy();

            // persist the new set of managed resources
            $store['managed_backends'] = $managedBackendsPreSave;
            $store['managed_frontends'] = $managedFrontendsPreSave;
            $this->saveStore($store);

            return true;
        } catch (\Exception $e) {
            $this->log('failed update/reload: '.$e->getMessage().' ('.$e->getCode().')');
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
            case 'frontend':
                return $resource['definition'];
                break;
            case 'node-service':
                $serviceNamespace = ($resource['serviceNamespace']) ? $resource['serviceNamespace'] : $kubernetesResource['metadata']['namespace'];
                $serviceName = $resource['serviceName'];
                $servicePort = $resource['servicePort'];

                $service = $controller->getKubernetesClient()->request("/api/v1/namespaces/{$serviceNamespace}/services/{$serviceName}");

                $hosts = [];
                // if service does not exist this is NULL
                switch ($service['spec']['type']) {
                    case 'LoadBalancer':
                        $port = self::getServicePortForHAProxy($service, $servicePort);
                        if ($port) {
                            $hosts[] = [
                                'name' => $service['metadata']['name'],
                                'address' => $service['status']['loadBalancer']['ingress'][0]['ip'],
                                'port' => $port
                            ];
                        }
                        break;
                    case 'NodePort':
                        $port = self::getServicePortForHAProxy($service, $servicePort);
                        if ($port) {
                            foreach ($this->state['nodes'] as $node) {
                                $hosts[] = [
                                    'name' => $node['metadata']['name'],
                                    'address' => KubernetesUtils::getNodeIp($node),
                                    'port' => $port
                                ];
                            }
                        }
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
        }
    }

    /**
     * Does a sanity check to prevent over-aggressive updates when watch resources are technically
     * modified but the things we care about are not
     *
     * @param $event
     * @param $oldItem
     * @param $item
     * @param $stateKey
     * @param $options
     * @return bool
     */
    public function shouldTriggerFromWatchUpdate($event, $oldItem, $item, $stateKey, $options = [])
    {
        switch ($stateKey) {
            case 'services':
                // abort immediately if not relevant to plugin
                if (!$this->serviceIsUsedByHAProxy($item)) {
                    return false;
                }

                // ADDED / DELETED
                if ($oldItem === null) {
                    return true;
                }

                // type changes
                if ($oldItem['spec']['type'] !== $item['spec']['type']) {
                    return true;
                }

                // ip changes for LoadBalancer services
                if ($oldItem['spec']['type'] == "LoadBalancer" && $item['spec']['type'] == "LoadBalancer") {
                    if ($oldItem['status']['loadBalancer']['ingress'][0]['ip'] != $item['status']['loadBalancer']['ingress'][0]['ip']) {
                        return true;
                    }
                }

                // port changes
                $oldPortHash = md5(json_encode($oldItem['spec']['ports']));
                $newPortHash = md5(json_encode($item['spec']['ports']));
                if ($oldPortHash != $newPortHash) {
                    return true;
                }

                return false;
                break;
        }

        return true;
    }

    private function serviceIsUsedByHAProxy($service)
    {
        foreach ($this->state['configmaps'] as $configmap) {
            foreach (yaml_parse($configmap['data']['data'])['resources'] as $resource) {
                switch ($resource['type']) {
                    case 'backend':
                        foreach ($resource['ha_servers'] as $server) {
                            if ($server['type'] == 'node-service') {
                                $serviceNamespace = ($server['serviceNamespace']) ? $server['serviceNamespace'] : $configmap['metadata']['namespace'];
                                $serviceName = $server['serviceName'];
                                if ($service['metadata']['name'] == $serviceName && $service['metadata']['namespace'] == $serviceNamespace) {
                                    return true;
                                }
                            }
                        }
                        break;
                }
            }
        }

        return false;
    }

    private static function getServicePortForHAProxy($service, $servicePort)
    {
        switch ($service['spec']['type']) {
            case 'LoadBalancer':
                foreach ($service['spec']['ports'] as $item) {
                    if ($item['port'] == $servicePort || $item['name'] == $servicePort) {
                        return $item['port'];
                        break;
                    }
                }
                break;
            case 'NodePort':
                foreach ($service['spec']['ports'] as $item) {
                    if ($item['port'] == $servicePort || $item['name'] == $servicePort) {
                        return $item['nodePort'];
                        break;
                    }
                }
                break;
        }
    }
}
