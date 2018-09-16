<?php

namespace KubernetesPfSenseController\Plugin;

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
    private function getNodeWatchCallback($stateKey)
    {
        return function ($event, $watch) use ($stateKey) {
            $key = $stateKey;
            $items = $this->state[$key];

            $item = $event['object'];
            unset($item['kind']);
            unset($item['apiVersion']);

            switch ($event['type']) {
                case 'ADDED':
                    $items[] = $item;
                    $this->state[$key] = $items;
                    $this->delayedAction();
                    break;
                case 'DELETED':
                    $result = KubernetesUtils::findListItem($items, $item['metadata']['name']);
                    $itemKey = $result['key'];
                    unset($items[$itemKey]);

                    $items = array_values($items);
                    $this->state[$key] = $items;
                    $this->delayedAction();
                    break;
                case 'MODIFIED':
                    $nodeIp = KubernetesUtils::getNodeIp($item);

                    $result = KubernetesUtils::findListItem($items, $item['metadata']['name']);
                    $itemKey = $result['key'];
                    $oldNode = $result['item'];
                    $oldNodeIp = KubernetesUtils::getNodeIp($oldNode);

                    $items[$itemKey] = $item;
                    $this->state[$key] = $items;

                    if ($oldNodeIp != $nodeIp) {
                        $this->log("NodeIP Address Changed - NewIp: ${nodeIp}, OldIP: ${oldNodeIp}");
                        $this->delayedAction();
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
            $items = $this->state[$key];

            $item = $event['object'];
            unset($item['kind']);
            unset($item['apiVersion']);

            switch ($event['type']) {
                case 'ADDED':
                    $items[] = $item;
                    $this->state[$key] = $items;
                    if ($trigger) {
                        $this->delayedAction();
                    }
                    break;
                case 'DELETED':
                    $result = KubernetesUtils::findListItem($items, $item['metadata']['name'], $item['metadata']['namespace']);
                    $itemKey = $result['key'];
                    unset($items[$itemKey]);

                    $items = array_values($items);
                    $this->state[$key] = $items;
                    if ($trigger) {
                        $this->delayedAction();
                    }
                    break;
                case 'MODIFIED':
                    $result = KubernetesUtils::findListItem($items, $item['metadata']['name'], $item['metadata']['namespace']);
                    $itemKey = $result['key'];
                    $items[$itemKey] = $item;
                    $this->state[$key] = $items;
                    if ($trigger) {
                        $this->delayedAction();
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
        $name = KubernetesUtils::getResourceName($resource);
        $namespace = KubernetesUtils::getResourceNamespace($resource);
        $selfLink = KubernetesUtils::getResourceSelfLink($resource);

        $values = [
            'selfLink' => $selfLink,
            'name' => $name,
        ];

        if (!empty($namespace)) {
            $values['namespace'] = $namespace;
        }

        return $values;
    }
}
