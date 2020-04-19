<?php

namespace KubernetesPfSenseController\Plugin;

/**
 * Utilities
 *
 * Class Utils
 * @package KubernetesPfSenseController\Plugin
 */
class Utils
{
    /**
     * Given a list of items, get the item matching the given id/keys
     *
     * @param $list
     * @param $itemId
     * @param $itemKeyName
     * @return mixed
     */
    public static function getListItem(&$list, $itemId, $itemKeyName)
    {
        foreach ($list as $key => $item) {
            if ($itemId == $item[$itemKeyName]) {
                return $item;
            }
        }
    }

    /**
     * Given a list of items, get the item matching the given id/keys
     *
     * @param $list
     * @param $itemId
     * @param $itemKeyNames
     * @return mixed
     */
    public static function getListItemMultiKey(&$list, $itemId, $itemKeyNames)
    {
        foreach ($list as $key => $item) {
            foreach ($itemKeyNames as $itemKeyName) {
                if (!key_exists($itemKeyName, $item)) {
                    continue 2;
                }
                if ($itemId[$itemKeyName] != $item[$itemKeyName]) {
                    continue 2;
                }
            }

            return $list[$key];
        }
    }

    /**
     * Add/update a list item matching the given id/keys
     *
     * @param $list
     * @param $item
     * @param $itemKeyName
     */
    public static function putListItem(&$list, $item, $itemKeyName)
    {
        foreach ($list as $key => $i_item) {
            if (empty($i_item)) {
                continue;
            }
            if ($item[$itemKeyName] == $i_item[$itemKeyName]) {
                $list[$key] = $item;

                return;
            }
        }

        $list[] = $item;
    }

    /**
     * Add/update a list item matching the given id/keys
     *
     * @param $list
     * @param $item
     * @param $itemKeyNames
     */
    public static function putListItemMultiKey(&$list, $item, $itemKeyNames)
    {
        foreach ($list as $key => $i_item) {
            if (empty($i_item)) {
                continue;
            }

            foreach ($itemKeyNames as $itemKeyName) {
                if ($item[$itemKeyName] != $i_item[$itemKeyName]) {
                    continue 2;
                }
            }

            $list[$key] = $item;

            return;
        }

        $list[] = $item;
    }

    /**
     * Remove a list item matching the given id/keys
     *
     * @param $list
     * @param $itemId
     * @param $itemKeyName
     * @return bool
     */
    public static function removeListItem(&$list, $itemId, $itemKeyName)
    {
        foreach ($list as $key => $item) {
            if ($itemId == $item[$itemKeyName]) {
                unset($list[$key]);
                $list = array_values($list);

                return true;
            }
        }

        return false;
    }

    /**
     * Remove a list item matching the given id/keys
     *
     * @param $list
     * @param $itemId
     * @param $itemKeyNames
     * @return bool
     */
    public static function removeListItemMultiKey(&$list, $itemId, $itemKeyNames)
    {
        foreach ($list as $key => $item) {
            foreach ($itemKeyNames as $itemKeyName) {
                if ($itemId[$itemKeyName] != $item[$itemKeyName]) {
                    continue 2;
                }
            }

            unset($list[$key]);
            $list = array_values($list);

            return true;
        }

        return false;
    }
}
