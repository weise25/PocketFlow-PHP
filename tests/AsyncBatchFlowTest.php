<?php
namespace PocketFlow\Tests;

use PHPUnit\Framework\TestCase;
use PocketFlow\AsyncNode;
use PocketFlow\AsyncFlow;
use PocketFlow\AsyncBatchFlow;
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
            // Ein Array, um die Start- und Endzeiten jedes Tasks zu speichern
            $shared->timestamps = [];

            $processNode = new class extends AsyncNode {
                public function exec_async(mixed $p): PromiseInterface {
                    return async(function() {
                        $id = $this->params['id'];
                        $shared = $this->params['shared_state'];
                        
                        // Zeichne den Startzeitpunkt auf
                        $shared->timestamps[$id]['start'] = microtime(true);
                        
                        await(sleep(0.02)); // Kurze Latenz
                        
                        // Zeichne den Endzeitpunkt auf
                        $shared->timestamps[$id]['end'] = microtime(true);
                        
                        return "Processed: {$id}";
                    })();
                }
                public function post_async(stdClass $shared, mixed $p, mixed $execResult): PromiseInterface {
                    return async(function() use ($shared, $execResult) {
                        $shared->results[] = $execResult;
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

            // Übergebe den shared-Store an die params, damit die Knoten darauf zugreifen können
            $parallelBatchFlow->setParams(['shared_state' => $shared]);
            await($parallelBatchFlow->run_async($shared));

            $this->assertCount(3, $shared->results);
            $this->assertCount(3, $shared->timestamps);

            // Logischer Beweis der Parallelisierung:
            // Der Startzeitpunkt von Task B muss VOR dem Endzeitpunkt von Task A liegen.
            // Wenn sie sequentiell wären, wäre der Start von B NACH dem Ende von A.
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