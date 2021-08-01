<?php

namespace KubernetesPfSenseController\XmlRpc;

/**
 * XmlRpc Client for communication with pfSense
 * Class Client
 * @package KubernetesPfSenseController\XmlRpc
 */
class Client extends \Laminas\XmlRpc\Client
{
    /**
     * pfSense version data
     *
     * @var array
     */
    private $pfSenseHostFirmwareVersion = [];

    private $pfSenseHostFirmwareVersionRefreshTime = 0;
    private $pfSenseHostFirmwareVersionRefreshInterval = 300;

    public function updatePfSenseVersionInfo()
    {
        // NOTE: before version 2.5.2 the second 'timeout' parameter below was not present, including it in older versions still seems to work however
        $this->pfSenseHostFirmwareVersion = $this->call('pfsense.host_firmware_version', [1, 60]);
        $this->pfSenseHostFirmwareVersionRefreshTime = time();
    }

    public function getPfSenseVersion()
    {
        if (empty($this->pfSenseHostFirmwareVersion) || time() > ($this->pfSenseHostFirmwareVersionRefreshTime + $this->pfSenseHostFirmwareVersionRefreshInterval)) {
            $this->updatePfSenseVersionInfo();
        }

        $version = $this->pfSenseHostFirmwareVersion['firmware']['version'];
        return substr($version, 0, strpos($version, "-"));
    }

    public function getPfSenseVersionMajor()
    {
        $version = $this->getPfSenseVersion();
        return explode('.', $version)[0];
    }

    public function getPfSenseVersionMinor()
    {
        $version = $this->getPfSenseVersion();
        return explode('.', $version)[1];
    }

    public function getPfSenseVersionPatch()
    {
        $version = $this->getPfSenseVersion();
        return explode('.', $version)[2];
    }

    public function call($method, $params = [])
    {
        $value = parent::call($method, $params);

        if (getenv('PFSENSE_DEBUG')) {
            $req = $this->getHttpClient()->getRequest();
            $res = $this->getHttpClient()->getResponse();

            $resContent = 'HTTP/'.$res->getVersion().' '.$res->getStatusCode().' '.$res->getReasonPhrase();
            $resContent .= "\n";
            $resContent .= $res->getHeaders()->toString();
            $resContent .= "\n";
            $resContent .= $res->getBody();

            $this->log('###################### START XMLRPC LOG ##########################');
            echo $req.PHP_EOL.PHP_EOL.$resContent;
            echo "\n";
            $this->log('######################## END XMLRPC LOG ##########################');
        }

        return $value;
    }

    public function log($message)
    {
        echo date("c").' '.$message."\n";
    }
}
