<?php
declare(strict_types=1);

namespace PocketFlow;

use React\Promise\PromiseInterface;
use function React\Async\async;
use function React\Async\await;

/**
 * An async flow that orchestrates a graph of nodes.
 *
 * AsyncFlow can handle both sync and async nodes. Sync nodes are executed
 * normally, while async nodes are awaited. Use runAsync() to start
 * the orchestration.
 */
class AsyncFlow extends Flow implements AsyncRunnable
{
    /**
     * Orchestrate the flow asynchronously.
     *
     * @param SharedStore $shared The shared data store
     * @param array|null $params Optional runtime parameters
     * @return PromiseInterface<string|null> The final action
     */
    public function _orchestrateAsync(SharedStore $shared, ?array $params = null): PromiseInterface
    {
        return async(function () use ($shared, $params) {
            $current = $this->startNode;
            $p = $params ?? $this->params;
            $lastAction = null;

            while ($current) {
                $current->setParams($p);
                if ($current instanceof AsyncRunnable) {
                    $lastAction = await($current->_runAsync($shared));
                } else {
                    $lastAction = $current->_run($shared);
                }
                $current = $this->getNextNode($current, $lastAction);
            }
            return $lastAction;
        })();
    }

    /**
     * Internal async run method.
     *
     * @param SharedStore $shared The shared data store
     * @return PromiseInterface<string|null> The final action
     */
    public function _runAsync(SharedStore $shared): PromiseInterface
    {
        return $this->_orchestrateAsync($shared, $this->params);
    }

    /**
     * Run this async flow.
     *
     * @param SharedStore $shared The shared data store
     * @return PromiseInterface<string|null> The final action
     */
    public function runAsync(SharedStore $shared): PromiseInterface
    {
        return $this->_runAsync($shared);
    }

    /**
     * Sync run is not supported for async flows.
     *
     * @param SharedStore $shared The shared data store
     * @return never
     * @throws \RuntimeException Always thrown
     */
    public function run(SharedStore $shared): ?string
    {
        throw new \RuntimeException("Cannot call sync 'run' on an AsyncFlow. Use 'runAsync' instead.");
    }

    /**
     * Internal sync run should not be called.
     *
     * @param SharedStore $shared The shared data store
     * @return never
     * @throws \RuntimeException Always thrown
     */
    protected function _run(SharedStore $shared): ?string
    {
        throw new \RuntimeException("Internal error: _run should not be called on AsyncFlow.");
    }
}
