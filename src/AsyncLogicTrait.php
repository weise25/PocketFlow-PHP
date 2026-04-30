<?php
declare(strict_types=1);

namespace PocketFlow;

use React\Promise\PromiseInterface;
use Throwable;
use function React\Async\async;
use function React\Async\await;
use function React\Promise\Timer\sleep as async_sleep;

/**
 * Trait providing async execution capabilities for nodes.
 *
 * Classes using this trait can be executed within an AsyncFlow and
 * return Promises instead of blocking values.
 */
trait AsyncLogicTrait
{
    protected int $maxRetries = 1;
    protected int $wait = 0;

    /**
     * Prepare data before async execution.
     *
     * Override to perform async setup work. The return value
     * (wrapped in a Promise) is passed to execAsync().
     *
     * @param SharedStore $shared The shared data store
     * @return PromiseInterface<mixed> Data to pass to execAsync()
     */
    public function prepAsync(SharedStore $shared): PromiseInterface { return async(fn() => null)(); }

    /**
     * Execute the main async logic of this node.
     *
     * Override to implement async core functionality.
     *
     * @param mixed $prepResult The result from prepAsync()
     * @return PromiseInterface<mixed> The result of execution
     */
    public function execAsync(mixed $prepResult): PromiseInterface { return async(fn() => null)(); }

    /**
     * Handle results after async execution.
     *
     * @param SharedStore $shared The shared data store
     * @param mixed $prepResult The result from prepAsync()
     * @param mixed $execResult The result from execAsync()
     * @return PromiseInterface<string|null> The action name to transition to
     */
    public function postAsync(SharedStore $shared, mixed $prepResult, mixed $execResult): PromiseInterface { return async(fn() => null)(); }

    /**
     * Handle the case when all async retries have been exhausted.
     *
     * @param mixed $prepResult The result from prepAsync()
     * @param Throwable $e The exception that was thrown
     * @return PromiseInterface<mixed> The fallback result
     */
    public function execFallbackAsync(mixed $prepResult, Throwable $e): PromiseInterface { return async(function() use ($e) { throw $e; })(); }

    /**
     * Internal async execution wrapper that handles retries.
     *
     * @param mixed $prepResult The result from prepAsync()
     * @return PromiseInterface<mixed> The result of execution
     */
    public function _execAsync(mixed $prepResult): PromiseInterface
    {
        return async(function () use ($prepResult) {
            for ($retryCount = 0; $retryCount < $this->maxRetries; $retryCount++) {
                try {
                    return await($this->execAsync($prepResult));
                } catch (Throwable $e) {
                    if ($retryCount === $this->maxRetries - 1) {
                        return await($this->execFallbackAsync($prepResult, $e));
                    }
                    if ($this->wait > 0) {
                        await(async_sleep($this->wait));
                    }
                }
            }
            return null;
        })();
    }

    /**
     * Internal async run method that executes the full lifecycle.
     *
     * @param SharedStore $shared The shared data store
     * @return PromiseInterface<string|null> The action from postAsync()
     */
    public function _runAsync(SharedStore $shared): PromiseInterface
    {
        return async(function () use ($shared) {
            $prepResult = await($this->prepAsync($shared));
            $execResult = await($this->_execAsync($prepResult));
            return await($this->postAsync($shared, $prepResult, $execResult));
        })();
    }

    /**
     * Run this async node in isolation.
     *
     * @param SharedStore $shared The shared data store
     * @return PromiseInterface<string|null> The action from postAsync()
     * @throws \RuntimeException If this node has successors
     */
    public function runAsync(SharedStore $shared): PromiseInterface
    {
        if (!empty($this->successors)) {
            throw new \RuntimeException("Cannot run an async node that has successors directly. Use an AsyncFlow to execute the full graph.");
        }
        return $this->_runAsync($shared);
    }

    /**
     * Sync run is not supported for async nodes.
     *
     * @param SharedStore $shared The shared data store
     * @return never
     * @throws \RuntimeException Always thrown
     */
    public function run(SharedStore $shared): ?string
    {
        throw new \RuntimeException("Cannot call sync 'run' on an async node. Use 'runAsync' instead.");
    }
}
