<?php

namespace KubernetesPfSenseController\Plugin;

/**
 *
 *
 * Class HAProxyConfig
 * @package KubernetesPfSenseController\Plugin
 */
class HAProxyConfig extends PfSenseConfigBlock
{
    /**
     * Save config to pfSense
     *
     * @throws \Exception
     */
    public function save()
    {
        //TODO: rollback <br/>[ALERT] 255/134856 (49880) : Proxy 'https-443': unable to find required default_backend: 'default_ipvANY'.
        parent::save();
    }

    /**
     * Get frontends
     *
     * @return mixed
     */
    public function getFrontends()
    {
        return $this->data['ha_backends']['item'];
    }

    /**
     * Get frontend names
     *
     * @return array
     */
    public function getFrontendNames()
    {
        $names = [];
        foreach ($this->getFrontends() as $item) {
            $names[] = $item['name'];
        }

        return $names;
    }

    /**
     * Get backends
     *
     * @return mixed
     */
    public function getBackends()
    {
        return $this->data['ha_pools']['item'];
    }

    /**
     * Get backend names
     *
     * @return array
     */
    public function getBackendNames()
    {
        $names = [];
        foreach ($this->getBackends() as $item) {
            $names[] = $item['name'];
        }

        return $names;
    }

    /**
     * Test frontend existence by name
     *
     * @param $name
     * @return bool
     */
    public function frontendExists($name)
    {
        foreach ($this->data['ha_backends']['item'] as $item) {
            if ($item['name'] == $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Test backend existence by name
     *
     * @param $name
     * @return bool
     */
    public function backendExists($name)
    {
        foreach ($this->data['ha_pools']['item'] as $item) {
            if ($item['name'] == $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get frontend
     *
     * @param $name
     * @return mixed
     */
    public function getFrontend($name)
    {
        foreach ($this->data['ha_backends']['item'] as $item) {
            if ($item['name'] == $name) {
                return $item;
            }
        }
    }

    /**
     * Get the index of a frontend
     *
     * @param $name
     * @return int|string
     */
    public function getFrontendKey($name)
    {
        foreach ($this->data['ha_backends']['item'] as $key => $item) {
            if ($item['name'] == $name) {
                return $key;
            }
        }
    }

    /**
     * Get backend
     *
     * @param $name
     * @return mixed
     */
    public function getBackend($name)
    {
        foreach ($this->data['ha_pools']['item'] as $item) {
            if ($item['name'] == $name) {
                return $item;
            }
        }
    }

    /**
     * Get the index of a backend
     *
     * @param $name
     * @return int|string
     */
    public function getBackendKey($name)
    {
        foreach ($this->data['ha_pools']['item'] as $key => $item) {
            if ($item['name'] == $name) {
                return $key;
            }
        }
    }

    /**
     * Remove frontend
     *
     * @param $name
     */
    public function removeFrontend($name)
    {
        $object = $this;
        array_walk($this->data['ha_backends']['item'], function ($item, $key) use ($name, $object) {
            if ($item['name'] == $name) {
                unset($object->data['ha_backends']['item'][$key]);
            }
        });

        // re key
        $this->data['ha_backends']['item'] = array_values($this->data['ha_backends']['item']);
    }

    /**
     * Remove backend
     *
     * @param $name
     */
    public function removeBackend($name)
    {
        $object = $this;
        array_walk($this->data['ha_pools']['item'], function ($item, $key) use ($name, $object) {
            if ($item['name'] == $name) {
                unset($object->data['ha_pools']['item'][$key]);
            }
        });

        // re key
        $this->data['ha_pools']['item'] = array_values($this->data['ha_pools']['item']);
    }

    /**
     * Replace/Add frontend
     *
     * @param $item
     */
    public function putFrontend($item)
    {
        if ($this->frontendExists($item['name'])) {
            $key = $this->getFrontendKey($item['name']);
            $this->data['ha_backends']['item'][$key] = $item;
        } else {
            $this->data['ha_backends']['item'][] = $item;
        }
    }

    /**
     * Replace/Add backend
     *
     * @param $item
     */
    public function putBackend($item)
    {
        if ($this->backendExists($item['name'])) {
            $key = $this->getBackendKey($item['name']);
            $this->data['ha_pools']['item'][$key] = $item;
        } else {
            $this->data['ha_pools']['item'][] = $item;
        }
    }
}
