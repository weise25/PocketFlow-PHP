<?php
declare(strict_types=1);

namespace PocketFlow;

use React\Promise\PromiseInterface;
use function React\Async\async;
use function React\Async\await;

/**
 * An async node that processes items sequentially.
 *
 * Similar to BatchNode, but all operations return Promises and are
 * awaited. Items are still processed one at a time (sequentially).
 */
class AsyncBatchNode extends AsyncNode
{
    /**
     * Execute the node's logic for each item, sequentially.
     *
     * @param mixed $items An array of items to process (from prepAsync())
     * @return PromiseInterface<array> An array of results, one per item
     */
    public function _execAsync(mixed $items): PromiseInterface
    {
        return async(function () use ($items) {
            $results = [];
            foreach ($items ?? [] as $item) {
                $results[] = await(parent::_execAsync($item));
            }
            return $results;
        })();
    }
}
