<?php
declare(strict_types=1);

namespace PocketFlow;

use React\Promise\PromiseInterface;
use function React\Async\async;
use function React\Async\await;
use function React\Promise\all;

/**
 * Async node that processes items in parallel.
 *
 * All tasks are started concurrently and the method waits for all of them
 * to settle. If any task throws, that first exception is re‑thrown after
 * the others have completed. This means side‑effects from other tasks
 * will have already taken place. Wrap your logic in transactions if
 * atomicity is required.
 */
class AsyncParallelBatchNode extends AsyncNode
{
    /**
     * Execute the node's logic for each item concurrently.
     *
     * @param mixed $items An array of items to process (from prepAsync())
     * @return PromiseInterface<array> An array of results, one per item
     * @throws \Throwable The first exception thrown by any item
     */
    public function _execAsync(mixed $items): PromiseInterface
    {
        return async(function () use ($items) {
            $promises = [];
            foreach ($items ?? [] as $item) {
                $promise = parent::_execAsync($item);
                // Manually implement "settle" logic
                $promises[] = $promise->then(
                    fn($value) => ['state' => 'fulfilled', 'value' => $value],
                    fn($reason) => ['state' => 'rejected', 'reason' => $reason]
                );
            }
            $results = await(all($promises));

            $finalResults = [];
            foreach ($results as $result) {
                if ($result['state'] === 'rejected') {
                    throw $result['reason'];
                }
                $finalResults[] = $result['value'];
            }
            return $finalResults;
        })();
    }
}
