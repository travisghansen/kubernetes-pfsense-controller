<?php

namespace KubernetesPfSenseController\Plugin;

use KubernetesClient\Client;

/**
 * Used by the plugins to provide standard set of features
 *
 * Trait CommonTrait
 * @package KubernetesPfSenseController\Plugin
 */
trait CommonTrait
{
    /**
     * Watch callback helper
     *
     * @param $stateKey
     * @return \Closure
     */
    private function getNodeWatchCallback($stateKey, $options = [])
    {
        return function ($event, $watch) use ($stateKey, $options) {
            if ($options['log']) {
                $this->logEvent($event);
            }

            if ($options['trigger'] !== false) {
                $trigger = true;
            } else {
                $trigger = false;
            }

            $key = $stateKey;
            $items = &$this->state[$key];

            $item = $event['object'];
            unset($item['kind']);
            unset($item['apiVersion']);

            switch ($event['type']) {
                // NOTE: sometimes ADDED events are triggered erroneously
                case 'ADDED':
                case 'MODIFIED':
                    $result = KubernetesUtils::findListItem($items, $item['metadata']['name']);
                    $itemKey = $result['key'];
                    $oldNode = $result['item'];

                    KubernetesUtils::putListItem($items, $item);

                    if ($itemKey === null) {
                        if ($trigger) {
                            $this->delayedAction();
                        }
                    } else {
                        $nodeIp = KubernetesUtils::getNodeIp($item);
                        $oldNodeIp = KubernetesUtils::getNodeIp($oldNode);

                        if ($oldNodeIp != $nodeIp) {
                            $this->log("NodeIP Address Changed - NewIp: ${nodeIp}, OldIP: ${oldNodeIp}");
                            if ($trigger) {
                                $this->delayedAction();
                            }
                        }
                    }
                    break;
                case 'DELETED':
                    $result = KubernetesUtils::findListItem($items, $item['metadata']['name']);
                    $itemKey = $result['key'];
                    if ($itemKey !== null) {
                        unset($items[$itemKey]);
                        $items = array_values($items);
                        if ($trigger) {
                            $this->delayedAction();
                        }
                    }
                    break;
            }
        };
    }

    /**
     * Watch callback helper
     *
     * @param $stateKey
     * @param array $options
     * @return \Closure
     */
    private function getWatchCallback($stateKey, $options = [])
    {
        return function ($event, $watch) use ($stateKey, $options) {
            if ($options['log']) {
                $this->logEvent($event);
            }

            if ($options['trigger'] !== false) {
                $trigger = true;
            } else {
                $trigger = false;
            }

            $key = $stateKey;
            $items = &$this->state[$key];

            $item = $event['object'];
            unset($item['kind']);
            unset($item['apiVersion']);

            switch ($event['type']) {
                case 'ADDED':
                case 'MODIFIED':
                    $result = KubernetesUtils::findListItem($items, $item['metadata']['name']);
                    $itemKey = $result['key'];
                    $oldItem = $result['item'];

                    KubernetesUtils::putListItem($items, $item);
                    if ($trigger) {
                        $shouldTriggerFromWatchUpdate = true;
                        if ($itemKey !== null && method_exists($this, 'shouldTriggerFromWatchUpdate')) {
                            $shouldTriggerFromWatchUpdate = $this->shouldTriggerFromWatchUpdate($oldItem, $item);
                        }
                        if ($shouldTriggerFromWatchUpdate) {
                            $this->delayedAction();
                        }
                    }
                    break;
                case 'DELETED':
                    $result = KubernetesUtils::findListItem($items, $item['metadata']['name'], $item['metadata']['namespace']);
                    $itemKey = $result['key'];
                    if ($itemKey !== null) {
                        unset($items[$itemKey]);
                        $items = array_values($items);
                        if ($trigger) {
                            $this->delayedAction();
                        }
                    }
                    break;
            }
        };
    }

    /**
     * Get a set of identifying information to bind managed resources by the controller to resources in the cluster
     *
     * @param $resource
     * @return array
     */
    protected function getKubernetesResourceDetails($resource)
    {
        $apiVersion = KubernetesUtils::getResourceApiVersion($resource);
        $kind = KubernetesUtils::getResourceKind($resource);
        $name = KubernetesUtils::getResourceName($resource);
        $namespace = KubernetesUtils::getResourceNamespace($resource);
        $selfLink = KubernetesUtils::getResourceSelfLink($resource);

        $values = [
            //'apiVersion' => $apiVersion,
            //'kind' => $kind,
            'selfLink' => $selfLink,
            'name' => $name,
        ];

        if (!empty($namespace)) {
            $values['namespace'] = $namespace;
        }

        return $values;
    }
}
