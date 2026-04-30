<?php
declare(strict_types=1);

namespace PocketFlow;

use Throwable;

/**
 * A standard node with retry and fallback logic.
 *
 * By default, exec() is called once. If it throws an exception, the node
 * will retry up to maxRetries times, waiting $wait seconds between attempts.
 * After all retries are exhausted, execFallback() is called.
 */
class Node extends BaseNode
{
    /**
     * @param int $maxRetries Maximum number of execution attempts (default: 1)
     * @param int $wait Seconds to wait between retries (default: 0)
     */
    public function __construct(public readonly int $maxRetries = 1, public readonly int $wait = 0) {}

    /**
     * Handle the case when all retries have been exhausted.
     *
     * Override this method to provide custom fallback behavior instead of
     * re-throwing the exception.
     *
     * @param mixed $prepResult The result from prep()
     * @param Throwable $e The exception that was thrown
     * @return mixed The fallback result
     */
    public function execFallback(mixed $prepResult, Throwable $e): mixed
    {
        throw $e;
    }

    /**
     * Internal execution wrapper that handles retries.
     *
     * @param mixed $prepResult The result from prep()
     * @return mixed The result of execution
     */
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
