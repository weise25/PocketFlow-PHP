<?php
declare(strict_types=1);

namespace PocketFlow;

/**
 * A node that processes a list of items sequentially.
 *
 * The prep() method should return an array of items. Each item is then
 * passed through exec() individually, and the results are collected
 * into an array and returned.
 */
class BatchNode extends Node
{
    /**
     * Execute the node's logic for each item in the list.
     *
     * @param mixed $items An array of items to process (from prep())
     * @return array An array of results, one per item
     */
    protected function _exec(mixed $items): mixed
    {
        $results = [];
        foreach ($items ?? [] as $item) {
            $results[] = parent::_exec($item);
        }
        return $results;
    }
}
