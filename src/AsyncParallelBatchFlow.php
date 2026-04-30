<?php
declare(strict_types=1);

namespace PocketFlow;

use React\Promise\PromiseInterface;
use function React\Async\async;
use function React\Async\await;
use function React\Promise\all;

/**
 * Async flow that runs sub-flows in parallel.
 *
 * All sub-flows are started concurrently and the method waits for all of them
 * to settle. If any sub-flow throws, that first exception is re‑thrown after
 * the others have completed. This means side‑effects from other sub-flows
 * will have already taken place. Wrap your logic in transactions if
 * atomicity is required.
 */
class AsyncParallelBatchFlow extends AsyncFlow
{
    /**
     * @param SharedStore $shared The shared data store
     * @return PromiseInterface<null> Resolves to null
     */
    public function prepAsync(SharedStore $shared): PromiseInterface { return async(fn() => null)(); }

    /**
     * @param SharedStore $shared The shared data store
     * @param mixed $prepResult Unused
     * @param mixed $execResult Unused
     * @return PromiseInterface<mixed> Passes through the input
     */
    public function postAsync(SharedStore $shared, mixed $prepResult, mixed $execResult): PromiseInterface { return async(fn() => $execResult)(); }

    /**
     * Execute the sub-flow for each parameter set concurrently.
     *
     * @param SharedStore $shared The shared data store
     * @return PromiseInterface<mixed> The result from postAsync()
     * @throws \Throwable The first exception thrown by any sub-flow
     */
    public function runAsync(SharedStore $shared): PromiseInterface
    {
        return async(function () use ($shared) {
            $paramList = await($this->prepAsync($shared)) ?? [];
            $promises = [];
            foreach ($paramList as $batchParams) {
                $promise = $this->_orchestrateAsync($shared, array_merge($this->params, $batchParams));
                // Manually implement "settle" logic
                $promises[] = $promise->then(
                    fn($value) => ['state' => 'fulfilled', 'value' => $value],
                    fn($reason) => ['state' => 'rejected', 'reason' => $reason]
                );
            }
            $results = await(all($promises));
            foreach ($results as $result) {
                if ($result['state'] === 'rejected') {
                    throw $result['reason'];
                }
            }
            return await($this->postAsync($shared, $paramList, null));
        })();
    }
}
