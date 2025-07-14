<?php
namespace PocketFlow\Tests;

use PHPUnit\Framework\TestCase;
use PocketFlow\Node;
use PocketFlow\AsyncNode;
use PocketFlow\AsyncFlow;
use React\Promise\PromiseInterface;
use stdClass;
use function React\Async\async;
use function React\Async\await;
use function React\Promise\Timer\sleep;

class AsyncFlowTest extends TestCase
{
    public function testAsyncFlowOrchestratesMixedNodes()
    {
        // Since the test itself is asynchronous, we need to wrap it in async/await.
        await(async(function() {
            $shared = new stdClass();
            $shared->execution_order = [];
            $shared->async_data = null;
            $shared->final_result = null;

            // An asynchronous node that "fetches" data.
            $asyncFetcher = new class extends AsyncNode {
                public function exec_async(mixed $p): PromiseInterface {
                    return async(function() {
                        await(sleep(0.01)); // Simulate I/O latency
                        return "Async Data Fetched";
                    })();
                }
                public function post_async(stdClass $shared, mixed $p, mixed $execResult): PromiseInterface {
                    return async(function() use ($shared, $execResult) {
                        $shared->execution_order[] = 'AsyncFetcher';
                        $shared->async_data = $execResult;
                        return 'default';
                    })();
                }
            };

            // A normal, synchronous node that processes the data.
            $syncProcessor = new class extends Node {
                public function prep(stdClass $shared): mixed {
                    return $shared->async_data;
                }
                public function exec(mixed $prepResult): string {
                    return "Processed: " . $prepResult;
                }
                public function post(stdClass $shared, mixed $p, mixed $execResult): ?string {
                    $shared->execution_order[] = 'SyncProcessor';
                    $shared->final_result = $execResult;
                    return null;
                }
            };

            $asyncFetcher->next($syncProcessor);
            $flow = new AsyncFlow($asyncFetcher);

            // Execute the asynchronous flow and wait for it to finish.
            await($flow->run_async($shared));

            // Check the results.
            $this->assertEquals(['AsyncFetcher', 'SyncProcessor'], $shared->execution_order);
            $this->assertEquals("Async Data Fetched", $shared->async_data);
            $this->assertEquals("Processed: Async Data Fetched", $shared->final_result);
        })());
    }

    /**
     * Tests error handling in an asynchronous flow.
     */
    public function testAsyncErrorHandlingInFlow()
    {
        await(async(function() {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage("Intentional async failure");

            $errorNode = new class extends AsyncNode {
                public function exec_async(mixed $p): PromiseInterface {
                    return async(function() {
                        await(sleep(0.01));
                        throw new \RuntimeException("Intentional async failure");
                    })();
                }
            };

            $flow = new AsyncFlow($errorNode);
            await($flow->run_async(new stdClass()));
        })());
    }
}