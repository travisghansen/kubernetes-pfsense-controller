<?php

namespace KubernetesPfSenseController\Plugin;

use Composer\Semver\Comparator;

/**
 * Object to interact with pfSense configuration data
 *
 * Class PfSenseConfigBlock
 * @package KubernetesPfSenseController\Plugin
 */
class PfSenseConfigBlock
{
    /**
     * Section type
     */
    public const ROOT_SECTION = 1;

    /**
     * Section type
     */
    public const INSTALLED_PACKAGE_SECTION = 2;

    /**
     * Default value for timeout param of xmlrpc calls
     */
    public const DEFAULT_XMLRPC_TIMEOUT = 60;

    /**
     * xmlrpc client instance
     *
     * @var \Zend\XmlRpc\Client
     */
    private $client;

    /**
     * Name of section
     *
     * @var string
     */
    private $sectionName;

    /**
     * Raw data from the config
     *
     * @var mixed
     */
    public $data;

    /**
     * Copy of the config data at time of creation
     *
     * @var mixed
     */
    private $originalData;

    /**
     * Section type
     *
     * @var int
     */
    private $sectionType;

    /**
     * Get named config block from root of configuration
     *
     * @param $client
     * @param $sectionName
     * @return mixed
     */
    public static function getRootConfigBlock($client, $sectionName)
    {
        $class = get_called_class();
        $section = [
            $sectionName
        ];
        $data = $client->call('pfsense.backup_config_section', [$section]);

        return new $class($client, $data[$sectionName], $sectionName, self::ROOT_SECTION);
    }

    /**
     * Get named config block from installedpackages key of configuration
     *
     * @param $client
     * @param $sectionName
     * @return mixed
     */
    public static function getInstalledPackagesConfigBlock($client, $sectionName)
    {
        $class = get_called_class();
        $section = [
            'installedpackages'
        ];
        $data = $client->call('pfsense.backup_config_section', [$section]);

        return new $class($client, $data['installedpackages'][$sectionName], $sectionName, self::INSTALLED_PACKAGE_SECTION);
    }

    /**
     * PfSenseConfigBlock constructor.
     * @param $client
     * @param $data
     * @param $sectionName
     * @param $sectionType
     */
    public function __construct($client, $data, $sectionName, $sectionType)
    {
        $this->client = $client;
        $this->data = $data;
        $this->originalData = $data;
        $this->sectionName = $sectionName;
        $this->sectionType = $sectionType;
    }

    /**
     * Get the sectionType
     *
     * @return int
     */
    public function getSectionType()
    {
        return $this->sectionType;
    }

    /**
     * Get the sectionName
     *
     * @return string
     */
    public function getSectionName()
    {
        return $this->sectionName;
    }

    /**
     * Save the config block to pfSense
     *
     * @throws \Exception
     */
    public function save()
    {
        try {
            $data = [
                $this->sectionName => $this->data
            ];

            switch ($this->sectionType) {
                case self::ROOT_SECTION:
                    $method = 'pfsense.restore_config_section';
                    break;
                case self::INSTALLED_PACKAGE_SECTION:
                    $method = 'pfsense.merge_installedpackages_section';
                    break;
            }

            $params = [$data];

            // https://github.com/pfsense/pfsense/commit/4f26f187d8cc5028646e86fbb95ce91552d062c2
            if (Comparator::greaterThanOrEqualTo($this->client->getPfSenseVersion(), '2.5.2')) {
                $params[] = self::DEFAULT_XMLRPC_TIMEOUT;
            }

            $response = $this->client->call($method, $params);
            if ($response !== true) {
                throw new \Exception("failed xmlrpc {$method} call");
            }
        } catch (\Exception $e) {
            throw $e;
        }
    }
}
