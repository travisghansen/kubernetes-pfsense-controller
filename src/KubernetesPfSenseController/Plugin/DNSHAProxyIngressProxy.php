<?php

namespace KubernetesPfSenseController\Plugin;

/**
 * Purpose..
 *
 * Class DNSHAProxyIngressProxy
 * @package KubernetesPfSenseController\Plugin
 */
class DNSHAProxyIngressProxy extends PfSenseAbstract
{
    use CommonTrait;
    /**
     * Unique plugin ID
     */
    public const PLUGIN_ID = 'pfsense-dns-haproxy-ingress-proxy';

    /**
     * Hash of haproxy-ingress-proxy state used to detect changes
     *
     * @var string
     */
    private $hash;

    /**
     * Init the plugin
     *
     * @throws \Exception
     */
    public function init()
    {
        $controller = $this->getController();

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

        $storeNamespace = $controller->getStoreNamespace();
        $storeName = $controller->getStoreName();
        $configMapResourceWatchPath = "/api/v1/watch/namespaces/{$storeNamespace}/configmaps/{$storeName}";

        // initial load of ingresses
        $params = [];
        $ingresses = $controller->getKubernetesClient()->createList($ingressResourcePath, $params)->get();
        $this->state['ingresses'] = $ingresses['items'];

        // watch for ingress changes
        $params = [
            'resourceVersion' => $ingresses['metadata']['resourceVersion'],
        ];
        $options = [
            'trigger' => false,
            'log' => true
        ];
        $watch = $controller->getKubernetesClient()->createWatch($ingressResourceWatchPath, $params, $this->getWatchCallback('ingresses', $options));
        $this->addWatch($watch);

        $this->state['controller_store'] = [];
        $options = [
            'trigger' => false,
            'log' => true
        ];
        $watch = $controller->getKubernetesClient()->createWatch($configMapResourceWatchPath, [], $this->getWatchCallback('controller_store', $options));
        $this->addWatch($watch);

        $this->generateHash();
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
        $hash = $this->hash;
        $this->generateHash();
        if ($this->hash != $hash) {
            $this->delayedAction();
        }
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
        $ingressProxyPlugin = $this->getController()->getPluginById(HAProxyIngressProxy::getPluginId());
        if ($ingressProxyPlugin == null) {
            return true;
        }

        $pluginConfig = $this->getConfig();

        $dnsmasqEnabled = $pluginConfig['dnsBackends']['dnsmasq']['enabled'] ?? false;
        $unboundEnabled = $pluginConfig['dnsBackends']['unbound']['enabled'] ?? false;

        // only supported options move along
        if (!$dnsmasqEnabled && !$unboundEnabled) {
            $this->log('plugin enabled without valid dnsBackends');
            return true;
        }

        $haProxyConfig = HAProxyConfig::getInstalledPackagesConfigBlock($this->getController()->getRegistryItem('pfSenseClient'), 'haproxy');

        $store = $this->getStore();
        if (empty($store)) {
            $store = [];
        }

        if (!key_exists('managed_hosts', $store)) {
            $store['managed_hosts'] = [];
        }
        $ingressProxyPluginStore = $ingressProxyPlugin->getStore();
        if (empty(($ingressProxyPluginStore))) {
            $ingressProxyPluginStore = [];
        }

        $managedFrontends = $ingressProxyPluginStore['managed_frontends'] ?? [];
        $managedHostsPreSave = [];
        $managedHosts = $store['managed_hosts'] ?? [];

        foreach ($managedFrontends as $frontendName => $frontendDetails) {
            $primaryFrontendName = $haProxyConfig->getFrontend($frontendName)['primary_frontend'] ?? null;
            if (empty($primaryFrontendName)) {
                continue;
            }
            $hostName = $pluginConfig['frontends'][$primaryFrontendName]['hostname'] ?? null;
            if (empty($hostName)) {
                continue;
            }

            $ingress = KubernetesUtils::getResourceByNamespaceName($this->state['ingresses'], $frontendDetails['resource']['namespace'], $frontendDetails['resource']['name']);
            if (!empty($ingress)) {
                foreach ($ingress['spec']['rules'] as $rule) {
                    if ($this->shouldCreateAlias($rule['host'])) {
                        if (empty($hostName)) {
                            $this->log(('missing hostname in config for primary frontend: '.$primaryFrontendName.', ingress host: '.$rule['host']));
                            continue;
                        }

                        $managedHostsPreSave[$hostName][] = $rule['host'];
                    }
                }
            }
        }

        if ($dnsmasqEnabled) {
            $dnsmasqConfig = PfSenseConfigBlock::getRootConfigBlock($this->getController()->getRegistryItem('pfSenseClient'), 'dnsmasq');
            if (!key_exists('hosts', $dnsmasqConfig->data)) {
                $dnsmasqConfig->data['hosts'] = [];
            }
        }

        if ($unboundEnabled) {
            $unboundConfig = PfSenseConfigBlock::getRootConfigBlock($this->getController()->getRegistryItem('pfSenseClient'), 'unbound');
            if (!key_exists('hosts', $unboundConfig->data)) {
                $unboundConfig->data['hosts'] = [];
            }
        }

        foreach ($managedHostsPreSave as $hostName => $aliases) {
            $itemId = [
                'host' => explode('.', $hostName, 2)[0],
                'domain' => explode('.', $hostName, 2)[1],
            ];
            $itemKey = ['host', 'domain'];

            if ($dnsmasqEnabled) {
                $this->log('setting dnsmasq host aliases for host: '.$hostName.', alias count: '. count($aliases));
                $host = null;
                $host = Utils::getListItemMultiKey($dnsmasqConfig->data['hosts'], $itemId, $itemKey);
                if ($host !== null) {
                    if (empty($aliases)) {
                        $host['aliases'] = '';
                    } else {
                        $host['aliases'] = [];
                        foreach ($aliases as $alias) {
                            $host['aliases']['item'][] = [
                                'host' => explode('.', $alias, 2)[0],
                                'domain' => explode('.', $alias, 2)[1],
                                'description' => 'created by kpc - do not edit',
                            ];
                        }
                    }

                    Utils::putListItemMultiKey($dnsmasqConfig->data['hosts'], $host, $itemKey);
                } else {
                    $this->log('missing dnsmasq host: '.$hostName.' to add aliases to');
                }
            }

            if ($unboundEnabled) {
                $this->log('setting unbound host aliases for host: '.$hostName.', alias count: '. count($aliases));
                $host = null;
                $host = Utils::getListItemMultiKey($unboundConfig->data['hosts'], $itemId, $itemKey);
                if ($host !== null) {
                    if (empty($aliases)) {
                        $host['aliases'] = '';
                    } else {
                        $host['aliases'] = [];
                        foreach ($aliases as $alias) {
                            $host['aliases']['item'][] = [
                                'host' => explode('.', $alias, 2)[0],
                                'domain' => explode('.', $alias, 2)[1],
                                'description' => 'created by kpc - do not edit',
                            ];
                        }
                    }

                    Utils::putListItemMultiKey($unboundConfig->data['hosts'], $host, $itemKey);
                } else {
                    $this->log('missing unbound host: '.$hostName.' to add aliases to');
                }
            }
        }

        $toDeleteHosts = array_diff(@array_keys($managedHosts), @array_keys($managedHostsPreSave));
        foreach ($toDeleteHosts as $hostName) {
            $itemId = [
                'host' => explode('.', $hostName, 2)[0],
                'domain' => explode('.', $hostName, 2)[1],
            ];
            $itemKey = ['host', 'domain'];

            if ($dnsmasqEnabled) {
                $this->log('removing dnsmasq host aliases for host: '.$hostName);
                $host = null;
                $host = Utils::getListItemMultiKey($dnsmasqConfig->data['hosts'], $itemId, $itemKey);
                if ($host !== null) {
                    $host['aliases'] = '';
                    Utils::putListItemMultiKey($dnsmasqConfig->data['hosts'], $host, $itemKey);
                }
            }

            if ($unboundEnabled) {
                $this->log('removing unbound host aliases for host: '.$hostName);
                $host = null;
                $host = Utils::getListItemMultiKey($unboundConfig->data['hosts'], $itemId, $itemKey);
                if ($host !== null) {
                    $host['aliases'] = '';
                    Utils::putListItemMultiKey($unboundConfig->data['hosts'], $host, $itemKey);
                }
            }
        }

        try {
            if ($dnsmasqEnabled && !empty($dnsmasqConfig)) {
                $this->savePfSenseConfigBlock($dnsmasqConfig);
                $this->reloadDnsmasq();
            }

            if ($unboundEnabled && !empty($unboundConfig)) {
                $this->savePfSenseConfigBlock($unboundConfig);
                $this->reloadUnbound();
            }

            $store['managed_hosts'] = $managedHostsPreSave;
            $this->saveStore($store);

            // reload DHCP sesrvice
            $this->reloadDHCP();

            return true;
        } catch (\Exception $e) {
            $this->log('failed update/reload: '.$e->getMessage().' ('.$e->getCode().')');
            return false;
        }
    }

    /**
     * If alias should be created
     *
     * @param $hostName
     * @return bool
     */
    private function shouldCreateAlias($hostName)
    {
        $ingressProxyPlugin = $this->getController()->getPluginById(HAProxyIngressProxy::getPluginId());
        $pluginConfig = $ingressProxyPlugin->getConfig();
        if (!empty($pluginConfig['allowedHostRegex'])) {
            $allowed = @preg_match($pluginConfig['allowedHostRegex'], $hostName);
            if ($allowed !== 1) {
                return false;
            }
        }

        $pluginConfig = $this->getConfig();
        if (!empty($pluginConfig['allowedHostRegex'])) {
            $allowed = @preg_match($pluginConfig['allowedHostRegex'], $hostName);
            if ($allowed !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Create a hash of the haproxy-ingress-proxy state for comparison
     */
    private function generateHash()
    {
        $ingressProxyPlugin = $this->getController()->getPluginById(HAProxyIngressProxy::getPluginId());
        if ($ingressProxyPlugin == null) {
            $this->hash = null;
            return;
        }

        $ingressProxyPluginStore = $ingressProxyPlugin->getStore();

        $hash = md5(json_encode($ingressProxyPluginStore));
        $this->hash = $hash;
    }
}
