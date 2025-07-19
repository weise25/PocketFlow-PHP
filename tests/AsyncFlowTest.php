<?php
declare(strict_types=1);

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
    /**
     * Tests that an AsyncFlow can correctly orchestrate a mix of async and sync nodes.
     */
    public function testAsyncFlowOrchestratesMixedNodes()
    {
        await(async(function() {
            $shared = new stdClass();
            $shared->execution_order = [];

            // An asynchronous node that simulates fetching data.
            $asyncFetcher = new class extends AsyncNode {
                public function post_async(stdClass $shared, mixed $p, mixed $e): PromiseInterface {
                    return async(function() use ($shared) {
                        await(sleep(0.01));
                        $shared->execution_order[] = 'AsyncFetcher';
                        $shared->async_data = "Async Data Fetched";
                        return 'default';
                    })();
                }
            };
            // A regular synchronous node that processes the data.
            $syncProcessor = new class extends Node {
                public function post(stdClass $shared, mixed $p, mixed $e): ?string {
                    $shared->execution_order[] = 'SyncProcessor';
                    $shared->final_result = "Processed: " . $shared->async_data;
                    return null;
                }
            };

            $asyncFetcher->next($syncProcessor);
            $flow = new AsyncFlow($asyncFetcher);
            await($flow->run_async($shared));

            $this->assertEquals(['AsyncFetcher', 'SyncProcessor'], $shared->execution_order);
            $this->assertEquals("Processed: Async Data Fetched", $shared->final_result);
        })());
    }

    /**
     * Tests that an exception in an AsyncNode correctly propagates up through the AsyncFlow.
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