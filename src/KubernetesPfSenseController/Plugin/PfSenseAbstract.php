<?php

namespace KubernetesPfSenseController\Plugin;

/**
 * Common methods to interact with pfSense
 *
 * Class PfSenseAbstract
 * @package KubernetesPfSenseController\Plugin
 */
abstract class PfSenseAbstract extends \KubernetesController\Plugin\AbstractPlugin
{
    /**
     * Save a pfSense configuration block
     *
     * @param PfSenseConfigBlock $config
     * @throws \Exception
     */
    public function savePfSenseConfigBlock(PfSenseConfigBlock $config)
    {
        try {
            $config->save();
        } catch (\Exception $e) {
            $sectionName = $config->getSectionName();
            $this->log("failed saving {$sectionName} config: ".$e->getMessage().' ('.$e->getCode().')');
            throw $e;
        }
    }

    /**
     * Execute a PHP snippet on pfSense server
     *
     * @param $code
     * @return mixed
     * @throws \Exception
     */
    protected function pfSenseExecPhp($code)
    {
        $pfSenseClient = $this->getController()->getRegistryItem('pfSenseClient');

        try {
            return $pfSenseClient->call('pfsense.exec_php', [$code]);
        } catch (\Exception $e) {
            $this->log('failed exec_php call: '.$e->getMessage().' ('.$e->getCode().')');
            throw $e;
        }
    }

    /**
     * Reload openbgp service
     *
     * @throws \Exception
     */
    protected function reloadOpenbgp()
    {
        try {
            $code = <<<'EOT'
require_once '/usr/local/pkg/openbgpd.inc';
$toreturn = openbgpd_install_conf();
EOT;
            $this->pfSenseExecPhp($code);
            $this->log('successfully reloaded openbgp service');
        } catch (\Exception $e) {
            $this->log('failed reload openbgp service: '.$e->getMessage().' ('.$e->getCode().')');
            throw $e;
        }
    }

    /**
     * Reload frr bgp service
     *
     * @throws \Exception
     */
    protected function reloadFrrBgp()
    {
        try {
            $code = <<<'EOT'
require_once("/usr/local/pkg/frr.inc");
frr_generate_config_bgp();
restart_service_if_running("FRR bgpd");

$messages = null;
$ok = true;

if($messages == null) {
	$messages = "";
}

$toreturn = [
    'ok' => $ok,
    'messages' => $messages,
];
EOT;
            $this->pfSenseExecPhp($code);
            $this->log('successfully reloaded frr bgp service');
        } catch (\Exception $e) {
            $this->log('failed reload frr bgp service: '.$e->getMessage().' ('.$e->getCode().')');
            throw $e;
        }
    }

    /**
     * Reload dnsmasq service
     *
     * @throws \Exception
     */
    protected function reloadDnsmasq()
    {
        try {
            $code = <<<'EOT'
$toreturn = services_dnsmasq_configure(false);
EOT;
            $this->pfSenseExecPhp($code);
            $this->log('successfully reloaded dnsmasq service');
        } catch (\Exception $e) {
            $this->log('failed reload dnsmasq service: '.$e->getMessage().' ('.$e->getCode().')');
            throw $e;
        }
    }

    /**
     * Reload unbound service
     *
     * @throws \Exception
     */
    protected function reloadUnbound()
    {
        try {
            $code = <<<'EOT'
$toreturn = services_unbound_configure(false);
EOT;
            $this->pfSenseExecPhp($code);
            $this->log('successfully reloaded unbound service');
        } catch (\Exception $e) {
            $this->log('failed reload unbound service: '.$e->getMessage().' ('.$e->getCode().')');
            throw $e;
        }
    }

    /**
     * Reload DHCP service
     *
     * @throws \Exception
     */
    protected function reloadDHCP()
    {
        try {
            $code = <<<'EOT'
$toreturn = services_dhcpd_configure();
EOT;
            $response = $this->pfSenseExecPhp($code);
            if ($response !== true) {
                throw new \Exception('failed to restart DHCP');
            }
            $this->log('successfully reloaded DHCP service');
        } catch (\Exception $e) {
            $this->log('failed reload DHCP service: '.$e->getMessage().' ('.$e->getCode().')');
            throw $e;
        }
    }

    /**
     * Reload HAProxy service
     *
     * @throws \Exception
     */
    public function reloadHAProxy()
    {
        try {
            $code = <<<'EOT'
require_once("/usr/local/pkg/haproxy/haproxy.inc");
$messages = null;
$reload = 1;
$ok = haproxy_check_and_run($messages, $reload);

if($messages == null) {
	$messages = "";
}

$toreturn = [
    'ok' => $ok,
    'messages' => $messages,
];
EOT;
            $response = $this->pfSenseExecPhp($code);

            if ($response['ok'] !== true) {
                throw new \Exception($response['messages']);
            }

            if (!empty($response['messages'])) {
                $this->log('warnings from HAProxy: '. trim($response['messages']));
            }

            $this->log('successfully reloaded HAProxy service');
        } catch (\Exception $e) {
            $this->log('failed reload HAProxy service: '.$e->getMessage().' ('.$e->getCode().')');
            throw $e;
        }
    }
}
