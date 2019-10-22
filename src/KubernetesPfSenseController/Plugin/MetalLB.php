<?php

namespace KubernetesPfSenseController\Plugin;

/**
 * Purpose of plugin is to sync cluster node changes to the appropriate bgp implementation configuration.
 *
 * Class MetalLb
 * @package KubernetesPfSenseController\Plugin
 */
class MetalLB extends PfSenseAbstract
{
    /**
     * Unique plugin ID
     */
    const PLUGIN_ID = 'metallb';

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
        $nodeLabelSelector = $pluginConfig['nodeLabelSelector'];
        $nodeFieldSelector = $pluginConfig['nodeFieldSelector'];

        // metallb config
        $watch = $controller->getKubernetesClient()->createWatch('/api/v1/watch/namespaces/metallb-system/configmaps/config', [], $this->getMetalLbConfigWatchCallback());
        $this->addWatch($watch);

        // initial load of nodes
        $params = [
            'labelSelector' => $nodeLabelSelector,
            'fieldSelector' => $nodeFieldSelector,
        ];
        $nodes = $controller->getKubernetesClient()->request('/api/v1/nodes', 'GET', $params);
        $this->state['nodes'] = $nodes['items'];

        // watch for node changes
        $params = [
            'labelSelector' => $nodeLabelSelector,
            'fieldSelector' => $nodeFieldSelector,
            'resourceVersion' => $nodes['metadata']['resourceVersion'],
        ];
        $watch = $controller->getKubernetesClient()->createWatch('/api/v1/watch/nodes', $params, $this->getNodeWatchCallback('nodes'));
        $this->addWatch($watch);
        $this->delayedAction();
    }

    /**
     * Callback for the metallb configmqp
     *
     * @return \Closure
     */
    private function getMetalLbConfigWatchCallback()
    {
        return function ($event, $watch) {
            $this->logEvent($event);
            switch ($event['type']) {
                case 'ADDED':
                case 'MODIFIED':
                    $this->state['metallb-config'] = yaml_parse($event['object']['data']['config']);
                    $this->delayedAction();
                    break;
                case 'DELETED':
                    $this->state['metallb-config'] = null;
                    break;
            }
        };
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
     * @return bool
     */
    public function doAction()
    {
        $metalConfig = $this->state['metallb-config'];
        $pluginConfig = $this->getConfig();

        if (empty($metalConfig)) {
            return false;
        }

        switch ($pluginConfig['bgp-implementation']) {
            case 'openbgp':
                return $this->doActionOpenbgp();
                break;
            default:
                $this->log('unsupported bgp-implementation: '.$pluginConfig['bgp-implementation']);
                return false;
                break;
        }
    }

    /**
     * Update pfSense state for openbgp
     *
     * @return bool
     */
    private function doActionOpenbgp()
    {
        $metalConfig = $this->state['metallb-config'];
        $openbgpConfig = PfSenseConfigBlock::getInstalledPackagesConfigBlock($this->getController()->getRegistryItem('pfSenseClient'), 'openbgpdneighbors');
        if (empty($openbgpConfig->data)) {
            $openbgpConfig->data = [];
        }

        if (empty($openbgpConfig->data['config'])) {
            $openbgpConfig->data['config'] = [];
        }

        $pluginConfig = $this->getConfig();

        $bgpEnabled = false;
        foreach ($metalConfig['address-pools'] as $pool) {
            if ($pool['protocol'] == 'bgp') {
                $bgpEnabled = true;
                break;
            }
        }

        if ($bgpEnabled) {
            // add/remove as necessary
            $template = $pluginConfig['options']['openbgp']['template'];

            $nodes = $this->state['nodes'];
            $neighbors = [];
            $managedNeighborsPreSave = [];
            foreach ($nodes as $node) {
                $host = 'kpc-'.KubernetesUtils::getNodeIp($node);
                $managedNeighborsPreSave[$host] = [
                    'resource' => $this->getKubernetesResourceDetails($node),
                ];
                $neighbor = [
                    'descr' => $host,
                    'neighbor' => KubernetesUtils::getNodeIp($node),
                    'md5sigkey' => (string) $template['md5sigkey'],
                    'md5sigpass' => (string) $template['md5sigpass'],
                    'groupname' => (string) $template['groupname'],
                    'row' => $template['row'],
                ];
                $neighbors[] = $neighbor;
            }

            // get store data
            $store = $this->getStore();
            $managedNeighborNamesPreSave = @array_keys($managedNeighborsPreSave);
            $managedNeighborNames = @array_keys($store['openbgp']['managed_neighbors']);
            if (empty($managedNeighborNames)) {
                $managedNeighborNames = [];
            }

            // update config with new/updated items
            foreach ($neighbors as $neighbor) {
                Utils::putListItem($openbgpConfig->data['config'], $neighbor, 'descr');
            }

            // remove items from config
            $toDeleteItemNames = array_diff($managedNeighborNames, $managedNeighborNamesPreSave);
            foreach ($toDeleteItemNames as $itemId) {
                Utils::removeListItem($openbgpConfig->data['config'], $itemId, 'descr');
            }

            // prep config for save
            if (empty($openbgpConfig->data['config'])) {
                $openbgpConfig->data = null;
            }

            // save newly managed configuration
            try {
                $this->savePfSenseConfigBlock($openbgpConfig);
                $this->reloadOpenbgp();
                $store['openbgp']['managed_neighbors'] = $managedNeighborsPreSave;
                $this->saveStore($store);

                return true;
            } catch (\Exception $e) {
                $this->log('failed update/reload: '.$e->getMessage().' ('.$e->getCode().')');
                return false;
            }
        } else {
            //remove any nodes from config
            // get storage data
            $store = $this->getStore();
            $managedNeighborNames = @array_keys($store['openbgp']['managed_neighbors']);
            if (empty($managedNeighborNames)) {
                return true;
            }

            foreach ($managedNeighborNames as $itemId) {
                Utils::removeListItem($openbgpConfig->data['config'], $itemId, 'descr');
            }

            // save newly managed configuration
            try {
                $this->savePfSenseConfigBlock($openbgpConfig);
                $this->reloadOpenbgp();
                $store['openbgp']['managed_neighbors'] = [];
                $this->saveStore($store);

                return true;
            } catch (\Exception $e) {
                $this->log('failed update/reload: '.$e->getMessage().' ('.$e->getCode().')');
                return false;
            }
        }
    }
}
