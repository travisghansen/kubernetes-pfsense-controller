<?php

namespace KubernetesPfSenseController\Plugin;

/**
 * Class DNSIngresses
 * @package KubernetesPfSenseController\Plugin
 */
class DNSIngresses extends PfSenseAbstract
{
    /**
     * Unique Plugin ID
     */
    const PLUGIN_ID = 'pfsense-dns-ingresses';

    use CommonTrait;
    use DNSResourceTrait;

    /**
     * Init the plugin
     *
     * @throws \Exception
     */
    public function init()
    {
        $controller = $this->getController();
        $pluginConfig = $this->getConfig();
        $ingressLabelSelector = $pluginConfig['serviceLabelSelector'];
        $ingressFieldSelector = $pluginConfig['serviceFieldSelector'];

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
        $this->state['resources'] = $ingresses['items'];

        // watch for ingress changes
        $params = [
            'labelSelector' => $ingressLabelSelector,
            'fieldSelector' => $ingressFieldSelector,
            'resourceVersion' => $ingresses['metadata']['resourceVersion'],
        ];
        $watch = $controller->getKubernetesClient()->createWatch($ingressResourceWatchPath, $params, $this->getWatchCallback('resources', ['log' => true]));
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
     * Build a set of hosts that should have IP Address
     *
     * @param $resourceHosts
     * @param $ingress
     */
    public function buildResourceHosts(&$resourceHosts, $ingress)
    {
        $ip = KubernetesUtils::getIngressIp($ingress);
        if (empty($ip)) {
            return;
        }
        foreach ($ingress['spec']['rules'] as $rule) {
            if ($this->shouldCreateHost($rule['host'])) {
                $resourceHosts[$rule['host']] = [
                    'ip' => $ip,
                    'resource' => $ingress,
                ];
            }
        }
    }

    /**
     * If the hostname is a candidate for entry creation
     *
     * @param $hostName
     * @return bool
     */
    private function shouldCreateHost($hostName)
    {
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
