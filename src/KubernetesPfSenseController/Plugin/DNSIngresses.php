<?php

namespace KubernetesPfSenseController\Plugin;

/**
 * Class DNSIngresses
 * @package KubernetesPfSenseController\Plugin
 */
class DNSIngresses extends PfSenseAbstract
{
    use CommonTrait;
    use DNSResourceTrait;
    /**
     * Unique Plugin ID
     */
    public const PLUGIN_ID = 'pfsense-dns-ingresses';

    /**
     * Annotation to override default enabled
     */
    public const ENABLED_ANNOTATION_NAME = 'dns.pfsense.org/enabled';

    /**
     * Init the plugin
     *
     * @throws \Exception
     */
    public function init()
    {
        $controller = $this->getController();
        $pluginConfig = $this->getConfig();
        $ingressLabelSelector = $pluginConfig['serviceLabelSelector'] ?? null;
        $ingressFieldSelector = $pluginConfig['serviceFieldSelector'] ?? null;

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
        $pluginConfig = $this->getConfig();
        if (KubernetesUtils::getResourceAnnotationExists($ingress, self::ENABLED_ANNOTATION_NAME)) {
            $ingressDnsEnabledAnnotationValue = KubernetesUtils::getResourceAnnotationValue($ingress, self::ENABLED_ANNOTATION_NAME);
            $ingressDnsEnabledAnnotationValue = strtolower($ingressDnsEnabledAnnotationValue);

            if (in_array($ingressDnsEnabledAnnotationValue, ["true", "1"])) {
                $ingressDnsEnabled = true;
            } else {
                $ingressDnsEnabled = false;
            }
        } else {
            if (key_exists('defaultEnabled', $pluginConfig)) {
                $ingressDnsEnabled = (bool) $pluginConfig['defaultEnabled'];
            } else {
                $ingressDnsEnabled = true;
            }
        }

        if (!$ingressDnsEnabled) {
            return;
        }

        $ips = KubernetesUtils::getIngressIp($ingress);
        if (empty($ips)) {
            return;
        }
        foreach ($ingress['spec']['rules'] as $rule) {
            if ($this->shouldCreateHost($rule['host'])) {
                $resourceHosts[$rule['host']] = [
                    'ip' => implode(',', $ips),
                    'resource' => $ingress,
                ];
            } else {
var_dump("what");
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
