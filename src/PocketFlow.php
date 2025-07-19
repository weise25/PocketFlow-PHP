<?php
declare(strict_types=1);

namespace PocketFlow;

use React\Promise\PromiseInterface;
use stdClass;
use Throwable;
use function React\Async\await;
use function React\Async\async;
use function React\Promise\all; 
use function React\Promise\Timer\sleep as async_sleep;

// Marker interface to avoid reflection.
interface AsyncRunnable {}

// Helper class to enable the ->on('action')->next($node) syntax
class ConditionalTransition
{
    public function __construct(private BaseNode $source, private string $action) {}

    public function next(?BaseNode $target): ?BaseNode
    {
        return $this->source->next($target, $this->action);
    }
}

// Base class for all Nodes and Flows
abstract class BaseNode
{
    public array $params = [];
    protected array $successors = [];

    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    public function next(?BaseNode $node, string $action = 'default'): ?BaseNode
    {
        if (isset($this->successors[$action])) {
            trigger_error("Overwriting successor for action '{$action}'", E_USER_WARNING);
        }
        $this->successors[$action] = $node;
        return $node;
    }

    public function on(string $action): ConditionalTransition
    {
        return new ConditionalTransition($this, $action);
    }

    public function getSuccessors(): array
    {
        return $this->successors;
    }

    public function prep(stdClass $shared): mixed { return null; }
    public function exec(mixed $prepResult): mixed { return null; }
    public function post(stdClass $shared, mixed $prepResult, mixed $execResult): ?string { return null; }

    protected function _exec(mixed $prepResult): mixed
    {
        return $this->exec($prepResult);
    }

    protected function _run(stdClass $shared): ?string
    {
        $prepResult = $this->prep($shared);
        $execResult = $this->_exec($prepResult);
        return $this->post($shared, $prepResult, $execResult);
    }

    public function run(stdClass $shared): ?string
    {
        if (!empty($this->successors)) {
            trigger_error("Node won't run successors. Use a Flow to run the full graph.", E_USER_WARNING);
        }
        return $this->_run($shared);
    }
}

// A standard node with retry and fallback logic
class Node extends BaseNode
{
    public function __construct(public int $maxRetries = 1, public int $wait = 0) {}

    public function execFallback(mixed $prepResult, Throwable $e): mixed
    {
        throw $e;
    }

    protected function _exec(mixed $prepResult): mixed
    {
        for ($retryCount = 0; $retryCount < $this->maxRetries; $retryCount++) {
            try {
                return $this->exec($prepResult);
            } catch (Throwable $e) {
                if ($retryCount === $this->maxRetries - 1) {
                    return $this->execFallback($prepResult, $e);
                }
                if ($this->wait > 0) {
                    sleep($this->wait);
                }
            }
        }
        return null;
    }
}

// A node that processes a list of items sequentially
class BatchNode extends Node
{
    protected function _exec(mixed $items): mixed
    {
        $results = [];
        foreach ($items ?? [] as $item) {
            $results[] = parent::_exec($item);
        }
        return $results;
    }
}

// Orchestrates a graph of nodes
class Flow extends BaseNode
{
    public function __construct(protected ?BaseNode $startNode = null) {}

    public function start(BaseNode $startNode): BaseNode
    {
        $this->startNode = $startNode;
        return $startNode;
    }

    protected function getNextNode(?BaseNode $current, ?string $action): ?BaseNode
    {
        if (!$current) return null;
        $actionKey = $action ?? 'default';
        $successors = $current->getSuccessors();
        if (!array_key_exists($actionKey, $successors)) {
            if (!empty($successors)) {
                $availableActions = implode("', '", array_keys($successors));
                trigger_error("Flow ends: Action '{$actionKey}' not found in available actions: '{$availableActions}'", E_USER_WARNING);
            }
            return null;
        }
        return $successors[$actionKey];
    }

    protected function _orchestrate(stdClass $shared, ?array $params = null): ?string
    {
        $current = $this->startNode;
        $p = $params ?? $this->params;
        $lastAction = null;

        while ($current) {
            $current->setParams($p);
            if ($current instanceof AsyncRunnable) {
                 $lastAction = await($current->_run_async($shared));
            } else {
                 $lastAction = $current->_run($shared);
            }
            $current = $this->getNextNode($current, $lastAction);
        }
        return $lastAction;
    }

    protected function _run(stdClass $shared): ?string
    {
        $prepResult = $this->prep($shared);
        $orchestrationResult = $this->_orchestrate($shared);
        return $this->post($shared, $prepResult, $orchestrationResult);
    }

    public function post(stdClass $shared, mixed $prepResult, mixed $execResult): ?string
    {
        return $execResult;
    }
}

// A flow that runs its sub-flow for each item returned by prep()
class BatchFlow extends Flow
{
    protected function _run(stdClass $shared): ?string
    {
        $paramList = $this->prep($shared) ?? [];
        foreach ($paramList as $batchParams) {
            $this->_orchestrate($shared, array_merge($this->params, $batchParams));
        }
        return $this->post($shared, $paramList, null);
    }
}

// --- ASYNC IMPLEMENTATIONS ---

trait AsyncLogicTrait
{
    public int $maxRetries = 1;
    public int $wait = 0;

    public function prep_async(stdClass $shared): PromiseInterface { return async(fn() => null)(); }
    public function exec_async(mixed $prepResult): PromiseInterface { return async(fn() => null)(); }
    public function post_async(stdClass $shared, mixed $prepResult, mixed $execResult): PromiseInterface { return async(fn() => null)(); }
    public function exec_fallback_async(mixed $prepResult, Throwable $e): PromiseInterface { return async(function() use ($e) { throw $e; })(); }

    public function _exec_async(mixed $prepResult): PromiseInterface
    {
        return async(function () use ($prepResult) {
            for ($retryCount = 0; $retryCount < $this->maxRetries; $retryCount++) {
                try {
                    return await($this->exec_async($prepResult));
                } catch (Throwable $e) {
                    if ($retryCount === $this->maxRetries - 1) {
                        return await($this->exec_fallback_async($prepResult, $e));
                    }
                    if ($this->wait > 0) {
                        await(async_sleep($this->wait));
                    }
                }
            }
            return null;
        })();
    }

    public function _run_async(stdClass $shared): PromiseInterface
    {
        return async(function () use ($shared) {
            $prepResult = await($this->prep_async($shared));
            $execResult = await($this->_exec_async($prepResult));
            return await($this->post_async($shared, $prepResult, $execResult));
        })();
    }

    public function run_async(stdClass $shared): PromiseInterface
    {
        if (!empty($this->successors)) {
            trigger_error("Node won't run successors. Use an AsyncFlow to run the full graph.", E_USER_WARNING);
        }
        return $this->_run_async($shared);
    }

    public function run(stdClass $shared): ?string
    {
        throw new \RuntimeException("Cannot call sync 'run' on an async node. Use 'run_async' instead.");
    }
}

class AsyncNode extends BaseNode implements AsyncRunnable
{
    use AsyncLogicTrait;
    public function __construct(int $maxRetries = 1, int $wait = 0)
    {
        $this->maxRetries = $maxRetries;
        $this->wait = $wait;
    }
}

class AsyncBatchNode extends AsyncNode
{
    public function _exec_async(mixed $items): PromiseInterface
    {
        return async(function () use ($items) {
            $results = [];
            foreach ($items ?? [] as $item) {
                $results[] = await(parent::_exec_async($item));
            }
            return $results;
        })();
    }
}

class AsyncParallelBatchNode extends AsyncNode
{
    public function _exec_async(mixed $items): PromiseInterface
    {
        return async(function () use ($items) {
            $promises = [];
            foreach ($items ?? [] as $item) {
                $promise = parent::_exec_async($item);
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

class AsyncFlow extends Flow implements AsyncRunnable
{
    public function _orchestrate_async(stdClass $shared, ?array $params = null): PromiseInterface
    {
        return async(function () use ($shared, $params) {
            $current = $this->startNode;
            $p = $params ?? $this->params;
            $lastAction = null;

            while ($current) {
                $current->setParams($p);
                if ($current instanceof AsyncRunnable) {
                    $lastAction = await($current->_run_async($shared));
                } else {
                    $lastAction = $current->_run($shared);
                }
                $current = $this->getNextNode($current, $lastAction);
            }
            return $lastAction;
        })();
    }

    public function _run_async(stdClass $shared): PromiseInterface
    {
        return $this->_orchestrate_async($shared, $this->params);
    }

    public function run_async(stdClass $shared): PromiseInterface
    {
        return $this->_run_async($shared);
    }

    public function run(stdClass $shared): ?string
    {
        throw new \RuntimeException("Cannot call sync 'run' on an AsyncFlow. Use 'run_async' instead.");
    }

    protected function _run(stdClass $shared): ?string
    {
        throw new \RuntimeException("Internal error: _run should not be called on AsyncFlow.");
    }
}

class AsyncBatchFlow extends AsyncFlow
{
    public function prep_async(stdClass $shared): PromiseInterface { return async(fn() => null)(); }
    public function post_async(stdClass $shared, mixed $prepResult, mixed $execResult): PromiseInterface { return async(fn() => $execResult)(); }

    public function run_async(stdClass $shared): PromiseInterface
    {
        return async(function () use ($shared) {
            $paramList = await($this->prep_async($shared)) ?? [];
            foreach ($paramList as $batchParams) {
                await($this->_orchestrate_async($shared, array_merge($this->params, $batchParams)));
            }
            return await($this->post_async($shared, $paramList, null));
        })();
    }
}

class AsyncParallelBatchFlow extends AsyncFlow
{
    public function prep_async(stdClass $shared): PromiseInterface { return async(fn() => null)(); }
    public function post_async(stdClass $shared, mixed $prepResult, mixed $execResult): PromiseInterface { return async(fn() => $execResult)(); }

    public function run_async(stdClass $shared): PromiseInterface
    {
        return async(function () use ($shared) {
            $paramList = await($this->prep_async($shared)) ?? [];
            $promises = [];
            foreach ($paramList as $batchParams) {
                $promise = $this->_orchestrate_async($shared, array_merge($this->params, $batchParams));
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
            return await($this->post_async($shared, $paramList, null));
        })();
    }
}