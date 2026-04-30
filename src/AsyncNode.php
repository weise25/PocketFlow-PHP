<?php
declare(strict_types=1);

namespace PocketFlow;

/**
 * An async node with retry and fallback logic.
 *
 * AsyncNode provides the same retry/fallback behavior as Node, but
 * all methods return Promises and can be awaited. Use within an
 * AsyncFlow to orchestrate asynchronous workflows.
 */
class AsyncNode extends BaseNode implements AsyncRunnable
{
    use AsyncLogicTrait;

    /**
     * @param int $maxRetries Maximum number of execution attempts (default: 1)
     * @param int $wait Seconds to wait between retries (default: 0)
     */
    public function __construct(int $maxRetries = 1, int $wait = 0)
    {
        $this->maxRetries = $maxRetries;
        $this->wait = $wait;
    }
}
