<?php
declare(strict_types=1);

namespace PocketFlow;

use React\Promise\PromiseInterface;
use function React\Async\async;
use function React\Async\await;

/**
 * An async flow that runs its sub-flow for each item returned by prepAsync().
 *
 * Similar to BatchFlow, but all operations return Promises and are awaited.
 * The sub-flow is executed sequentially for each parameter set.
 */
class AsyncBatchFlow extends AsyncFlow
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
     * Execute the sub-flow for each parameter set.
     *
     * @param SharedStore $shared The shared data store
     * @return PromiseInterface<mixed> The result from postAsync()
     */
    public function runAsync(SharedStore $shared): PromiseInterface
    {
        return async(function () use ($shared) {
            $paramList = await($this->prepAsync($shared)) ?? [];
            foreach ($paramList as $batchParams) {
                await($this->_orchestrateAsync($shared, array_merge($this->params, $batchParams)));
            }
            return await($this->postAsync($shared, $paramList, null));
        })();
    }
}
