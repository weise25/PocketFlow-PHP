<?php
declare(strict_types=1);

namespace PocketFlow\Tests;

use PHPUnit\Framework\TestCase;
use PocketFlow\AsyncNode;
use PocketFlow\AsyncFlow;
use PocketFlow\AsyncParallelBatchFlow;
use React\Promise\PromiseInterface;
use stdClass;
use function React\Async\async;
use function React\Async\await;
use function React\Promise\Timer\sleep;

class AsyncBatchFlowTest extends TestCase
{
    public function testAsyncParallelBatchFlowProcessesFlowsConcurrently()
    {
        await(async(function() {
            $shared = new stdClass();
            $shared->results = [];
            // An array to store the start and end times of each task
            $shared->timestamps = [];

            $processNode = new class extends AsyncNode {
                public function post_async(stdClass $shared, mixed $p, mixed $e): PromiseInterface {
                    return async(function() use ($shared) {
                        $id = $this->params['id'];
                        
                        // Record the start time
                        $shared->timestamps[$id]['start'] = microtime(true);
                        
                        await(sleep(0.02)); // Short latency
                        
                        // Record the end time
                        $shared->timestamps[$id]['end'] = microtime(true);
                        
                        $shared->results[] = "Processed in parallel: {$id}";
                        return null;
                    })();
                }
            };

            $subFlow = new AsyncFlow($processNode);
            $parallelBatchFlow = new class($subFlow) extends AsyncParallelBatchFlow {
                public function prep_async(stdClass $shared): PromiseInterface {
                    return async(fn() => [['id' => 'A'], ['id' => 'B'], ['id' => 'C']])();
                }
            };

            await($parallelBatchFlow->run_async($shared));

            $this->assertCount(3, $shared->results);
            $this->assertCount(3, $shared->timestamps);

            // Logical proof of parallelization:
            // The start time of task B must be BEFORE the end time of task A.
            // If they were sequential, the start of B would be AFTER the end of A.
            $this->assertLessThan(
                $shared->timestamps['A']['end'],
                $shared->timestamps['B']['start'],
                "Task B should have started before Task A finished, proving parallel execution."
            );
            $this->assertLessThan(
                $shared->timestamps['B']['end'],
                $shared->timestamps['C']['start'],
                "Task C should have started before Task B finished, proving parallel execution."
            );
        })());
    }

    public function testErrorHandlingInParallelBatchFlow()
    {
        await(async(function() {
            $this->expectException(\ValueError::class);
            $this->expectExceptionMessage("Async error on B");

            $shared = new stdClass();

            $errorNode = new class extends AsyncNode {
                public function exec_async(mixed $p): PromiseInterface {
                    return async(function() {
                        if ($this->params['id'] === 'B') {
                            throw new \ValueError("Async error on B");
                        }
                        return null;
                    })();
                }
            };

            $subFlow = new AsyncFlow($errorNode);
            $parallelBatchFlow = new class($subFlow) extends AsyncParallelBatchFlow {
                public function prep_async(stdClass $shared): PromiseInterface {
                    return async(fn() => [['id' => 'A'], ['id' => 'B']])();
                }
            };

            await($parallelBatchFlow->run_async($shared));
        })());
    }
}