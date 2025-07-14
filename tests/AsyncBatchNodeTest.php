<?php
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
    public function testAsyncBatchNodeProcessesItemsSequentially()
    {
        await(async(function() {
            $shared = new stdClass();
            $shared->items = [1, 2, 3];
            $shared->results = null;
            $processing_times = [];

            $node = new class extends AsyncBatchNode {
                public function prep_async(stdClass $shared): PromiseInterface {
                    return async(fn() => $shared->items)();
                }
                public function exec_async(mixed $item): PromiseInterface {
                    return async(function() use ($item, &$processing_times) {
                        $start = microtime(true);
                        await(sleep(0.01)); // Short, fixed latency
                        $processing_times[] = microtime(true) - $start;
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
            // In sequential execution, the total time should be approximately the sum of the individual times.
            // 3 * 0.01s = 0.03s. We allow for a small tolerance.
            $this->assertGreaterThan(0.03, $total_time);
        })());
    }

    public function testAsyncParallelBatchNodeProcessesItemsConcurrently()
    {
        await(async(function() {
            $shared = new stdClass();
            $shared->items = [1, 2, 3];
            $shared->results = null;

            $node = new class extends AsyncParallelBatchNode {
                public function prep_async(stdClass $shared): PromiseInterface {
                    return async(fn() => $shared->items)();
                }
                public function exec_async(mixed $item): PromiseInterface {
                    return async(function() use ($item) {
                        await(sleep(0.02)); // Slightly longer latency
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

            // The results can be in any order, so we sort them.
            sort($shared->results);
            $this->assertEquals([2, 4, 6], $shared->results);
            // In parallel execution, the total time should be only slightly longer than the longest individual latency.
            // It should be significantly shorter than the sum of all latencies (3 * 0.02s = 0.06s).
            $this->assertLessThan(0.04, $total_time);
            $this->assertGreaterThan(0.02, $total_time);
        })());
    }

    /**
     * Tests error handling in a parallel batch.
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