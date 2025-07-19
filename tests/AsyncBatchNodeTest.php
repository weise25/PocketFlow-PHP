<?php
declare(strict_types=1);

namespace PocketFlow\Tests;

use PHPUnit\Framework\TestCase;
use PocketFlow\AsyncBatchNode;
use PocketFlow\AsyncParallelBatchNode;
use React\Promise\PromiseInterface;
use stdClass;
use function React\Async\async;
use function React\Async\await;
use function React\Promise\Timer\sleep;

class AsyncBatchNodeTest extends TestCase
{
    /**
     * Tests that AsyncBatchNode processes items sequentially (one after another).
     */
    public function testAsyncBatchNodeProcessesItemsSequentially()
    {
        await(async(function() {
            $shared = new stdClass();
            $shared->items = [1, 2, 3];

            $node = new class extends AsyncBatchNode {
                public function prep_async(stdClass $shared): PromiseInterface {
                    return async(fn() => $shared->items)();
                }
                public function exec_async(mixed $item): PromiseInterface {
                    return async(function() use ($item) {
                        await(sleep(0.01));
                        return $item * 2;
                    })();
                }
                public function post_async(stdClass $shared, mixed $p, mixed $execResult): PromiseInterface {
                    return async(function() use ($shared, $execResult) {
                        $shared->results = $execResult;
                        return null;
                    })();
                }
            };

            $start_time = microtime(true);
            await($node->run_async($shared));
            $total_time = microtime(true) - $start_time;

            $this->assertEquals([2, 4, 6], $shared->results);
            // Total time should be roughly the sum of individual latencies (3 * 0.01s).
            $this->assertGreaterThan(0.03, $total_time);
        })());
    }

    /**
     * Tests that AsyncParallelBatchNode processes items concurrently (at the same time).
     */
    public function testAsyncParallelBatchNodeProcessesItemsConcurrently()
    {
        await(async(function() {
            $shared = new stdClass();
            $shared->items = [1, 2, 3];

            $node = new class extends AsyncParallelBatchNode {
                public function prep_async(stdClass $shared): PromiseInterface {
                    return async(fn() => $shared->items)();
                }
                public function exec_async(mixed $item): PromiseInterface {
                    return async(function() use ($item) {
                        await(sleep(0.02));
                        return $item * 2;
                    })();
                }
                public function post_async(stdClass $shared, mixed $p, mixed $execResult): PromiseInterface {
                    return async(function() use ($shared, $execResult) {
                        $shared->results = $execResult;
                        return null;
                    })();
                }
            };

            $start_time = microtime(true);
            await($node->run_async($shared));
            $total_time = microtime(true) - $start_time;

            sort($shared->results);
            $this->assertEquals([2, 4, 6], $shared->results);
            // Total time should be slightly more than the longest single task, not the sum.
            $this->assertLessThan(0.04, $total_time);
        })());
    }

    /**
     * Tests that an error in one of the parallel tasks correctly bubbles up.
     */
    public function testErrorHandlingInParallelBatch()
    {
        await(async(function() {
            $this->expectException(\ValueError::class);
            $this->expectExceptionMessage("Error on item 2");

            $shared = new stdClass();
            $shared->items = [1, 2, 3];

            $errorNode = new class extends AsyncParallelBatchNode {
                public function prep_async(stdClass $shared): PromiseInterface {
                    return async(fn() => $shared->items)();
                }
                public function exec_async(mixed $item): PromiseInterface {
                    return async(function() use ($item) {
                        if ($item === 2) {
                            throw new \ValueError("Error on item 2");
                        }
                        await(sleep(0.01));
                        return $item;
                    })();
                }
            };

            await($errorNode->run_async($shared));
        })());
    }
}