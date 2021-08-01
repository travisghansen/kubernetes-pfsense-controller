<?php

namespace KubernetesPfSenseController\Plugin;

/**
 * Plugin exists to manage services of type LoadBalancer.  It will work with dnsmasq, unbound, or both.  To set the
 * hostname simply add an annotation to the the service as follows:
 * dns.pfsense.org/hostname: foo.bar.baz
 *
 * Class DNSServices
 * @package KubernetesPfSenseController\Plugin
 */
class DNSServices extends PfSenseAbstract
{
    use CommonTrait;
    use DNSResourceTrait;
    /**
     * Unique plugin ID
     */
    public const PLUGIN_ID = 'pfsense-dns-services';

    /**
     * Init the plugin
     *
     * @throws \Exception
     */
    public function init()
    {
        $controller = $this->getController();
        $pluginConfig = $this->getConfig();
        $serviceLabelSelector = $pluginConfig['serviceLabelSelector'] ?? null;
        $serviceFieldSelector = $pluginConfig['serviceFieldSelector'] ?? null;

        // initial load of services
        $params = [
            'labelSelector' => $serviceLabelSelector,
            'fieldSelector' => $serviceFieldSelector,
        ];
        $services = $controller->getKubernetesClient()->createList('/api/v1/services', $params)->get();
        $this->state['resources'] = $services['items'];

        // watch for service changes
        $params = [
            'labelSelector' => $serviceLabelSelector,
            'fieldSelector' => $serviceFieldSelector,
            'resourceVersion' => $services['metadata']['resourceVersion'],
        ];
        $watch = $controller->getKubernetesClient()->createWatch('/api/v1/watch/services', $params, $this->getWatchCallback('resources', ['log' => true]));
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
     * @param $service
     */
    public function buildResourceHosts(&$resourceHosts, $service)
    {
        if (!$this->shouldCreateHost($service)) {
            return;
        }
        $hostNames = KubernetesUtils::getServiceHostname($service);
        $ip = KubernetesUtils::getServiceIp($service);
        foreach ($hostNames as $hostName) {
            $resourceHosts[$hostName] = [
                'ip' => $ip,
                'resource' => $service,
            ];
        }
    }

    /**
     * If the service is a candidate for entry creation
     *
     * @param $service
     * @return bool
     */
    private function shouldCreateHost($service)
    {
        if ($service['spec']['type'] != 'LoadBalancer') {
            return false;
        }

        $hasAnnotation = KubernetesUtils::getResourceAnnotationExists($service, KubernetesUtils::SERVICE_HOSTNAME_ANNOTATION);

        if (!$hasAnnotation) {
            return false;
        }

        // ignore non provisioned services
        $ip = KubernetesUtils::getServiceIp($service);
        if (empty($ip)) {
            return false;
        }

        $pluginConfig = $this->getConfig();
        $hostNames = KubernetesUtils::getServiceHostname($service);
        if (!empty($pluginConfig['allowedHostRegex'])) {
            foreach ($hostNames as $hostName) {
                $allowed = @preg_match($pluginConfig['allowedHostRegex'], $hostName);
                if ($allowed !== 1) {
                    return false;
                }
            }
        }

        return true;
    }
}
