<?php
declare(strict_types=1);

namespace PocketFlow;

use React\Promise\PromiseInterface;
use Throwable;
use function React\Async\async;
use function React\Async\await;
use function React\Promise\all;

/**
 * Async node that processes items in parallel with saga-style compensation.
 *
 * Like AsyncParallelBatchNode, all tasks are started concurrently. However,
 * if any task fails, all already-completed tasks are automatically compensated
 * (rolled back) in reverse order before the exception is re-thrown.
 *
 * This prevents the partial-execution problem where some tasks have already
 * produced side effects (database writes, API calls, external mutations) while
 * others have failed — leaving the system in an inconsistent state.
 *
 * ## Usage
 *
 * Extend this class and implement both execAsync() and compensate():
 *
 * ```php
 * class SaveDocumentsNode extends TransactionalParallelBatchNode
 * {
 *     public function prepAsync(SharedStore $shared): PromiseInterface
 *     {
 *         return async(fn() => $shared->documents)();
 *     }
 *
 *     public function execAsync(mixed $document): PromiseInterface
 *     {
 *         return async(function () use ($document) {
 *             // e.g. write to DB, call external API, etc.
 *             $id = $this->params['db']->insert($document);
 *             return $id;
 *         })();
 *     }
 *
 *     public function compensate(mixed $item, mixed $result): PromiseInterface
 *     {
 *         return async(function () use ($result) {
 *             // Undo: delete the record that was inserted
 *             $this->params['db']->delete($result);
 *         })();
 *     }
 * }
 * ```
 *
 * ## Important caveats
 *
 * - Compensation is best-effort: if a compensate() call itself throws, the
 *   exception is caught and execution continues with the remaining compensations.
 *   The original exception is always the one that is finally re-thrown.
 * - Atomicity is not guaranteed for truly concurrent side effects that complete
 *   between the failure and the compensation sweep. Use database-level
 *   transactions where strict atomicity is required.
 * - Compensation runs in reverse order of completion, not reverse order of start.
 */
abstract class TransactionalParallelBatchNode extends AsyncNode
{
    /**
     * Compensate (undo) the side effects of a successfully completed task.
     *
     * This method is called automatically for every task that completed
     * successfully when another task in the same batch fails.
     *
     * Implement this method to reverse whatever execAsync() did: delete a
     * database record, cancel an API charge, remove a written file, etc.
     *
     * If compensation itself fails, the exception is swallowed and the next
     * compensation is attempted. The original failure exception is re-thrown
     * after all compensations have been attempted.
     *
     * @param mixed $item   The original input item that was passed to execAsync()
     * @param mixed $result The value that execAsync() returned for this item
     * @return PromiseInterface<void>
     */
    abstract public function compensate(mixed $item, mixed $result): PromiseInterface;

    /**
     * Execute all items concurrently, compensating completed tasks on failure.
     *
     * @param mixed $items An array of items to process (from prepAsync())
     * @return PromiseInterface<array> An array of results, one per item
     * @throws Throwable The original exception after all compensations are attempted
     */
    public function _execAsync(mixed $items): PromiseInterface
    {
        return async(function () use ($items) {
            $completed = []; // list of ['item' => ..., 'result' => ...]

            $promises = [];
            foreach ($items ?? [] as $item) {
                $promises[] = async(function () use ($item, &$completed) {
                    $result = await(parent::_execAsync($item));
                    // Record completion so we can compensate this item if a
                    // sibling task fails after this point.
                    $completed[] = ['item' => $item, 'result' => $result];
                    return $result;
                })()
                ->then(
                    fn($value) => ['state' => 'fulfilled', 'value' => $value],
                    fn($reason) => ['state' => 'rejected', 'reason' => $reason]
                );
            }

            $results = await(all($promises));

            // Check for failures.
            $firstException = null;
            foreach ($results as $result) {
                if ($result['state'] === 'rejected' && $firstException === null) {
                    $firstException = $result['reason'];
                }
            }

            if ($firstException !== null) {
                // Compensate all completed tasks in reverse order of completion.
                foreach (array_reverse($completed) as $done) {
                    try {
                        await($this->compensate($done['item'], $done['result']));
                    } catch (Throwable) {
                        // Compensation failures are swallowed; we must attempt
                        // all compensations before re-throwing the original error.
                    }
                }
                throw $firstException;
            }

            return array_column(
                array_filter($results, fn($r) => $r['state'] === 'fulfilled'),
                'value'
            );
        })();
    }
}
