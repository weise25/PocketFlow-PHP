<?php
namespace PocketFlow\Tests;

use PHPUnit\Framework\TestCase;
use PocketFlow\Node;
use PocketFlow\Flow;
use PocketFlow\BatchFlow;
use stdClass;
use ValueError;

class BatchFlowTest extends TestCase
{
    public function testBatchFlowProcessesAllParameterSets()
    {
        $shared = new stdClass();
        $shared->input_data = ['a' => 1, 'b' => 2, 'c' => 3];
        $shared->results = [];

        $processItemNode = new class extends Node {
            public function exec(mixed $p): mixed {
                $key = $this->params['key'];
                $this->params['shared']->results[$key] = $this->params['shared']->input_data[$key] * 2;
                return null;
            }
        };

        $subFlow = new Flow($processItemNode);
        $batchFlow = new class($subFlow) extends BatchFlow {
            public function prep(stdClass $shared): array {
                return array_map(fn($k) => ['key' => $k], array_keys($shared->input_data));
            }
        };

        $batchFlow->setParams(['shared' => $shared]);
        $batchFlow->run($shared);

        $this->assertEquals(['a' => 2, 'b' => 4, 'c' => 6], $shared->results);
    }

    public function testBatchFlowHandlesEmptyList()
    {
        $shared = new stdClass();
        $shared->results = [];

        $processItemNode = new class extends Node {
            public function exec(mixed $p): mixed {
                $this->fail("Exec should not be called for an empty batch.");
                return null;
            }
        };

        $subFlow = new Flow($processItemNode);
        $batchFlow = new class($subFlow) extends BatchFlow {
            public function prep(stdClass $shared): array { return []; }
        };

        $batchFlow->run($shared);
        $this->assertEmpty($shared->results);
    }

    /**
     * Tests error handling within a batch.
     */
    public function testErrorHandlingInBatch()
    {
        $this->expectException(ValueError::class);
        $this->expectExceptionMessage("Error processing key: error_key");

        $shared = new stdClass();
        $shared->input_data = ['ok_key' => 1, 'error_key' => 2];

        $errorNode = new class extends Node {
            public function exec(mixed $p): mixed {
                if ($this->params['key'] === 'error_key') {
                    throw new ValueError("Error processing key: error_key");
                }
                return null;
            }
        };

        $subFlow = new Flow($errorNode);
        $batchFlow = new class($subFlow) extends BatchFlow {
            public function prep(stdClass $shared): array {
                return array_map(fn($k) => ['key' => $k], array_keys($shared->input_data));
            }
        };

        $batchFlow->run($shared);
    }

    /**
     * Tests the passing of custom parameters.
     */
    public function testCustomParametersInBatch()
    {
        $shared = new stdClass();
        $shared->input_data = ['a' => 1, 'b' => 2, 'c' => 3];
        $shared->results = [];

        $customParamNode = new class extends Node {
            public function exec(mixed $p): mixed {
                $key = $this->params['key'];
                $multiplier = $this->params['multiplier'];
                $this->params['shared']->results[$key] = $this->params['shared']->input_data[$key] * $multiplier;
                return null;
            }
        };

        $subFlow = new Flow($customParamNode);
        $batchFlow = new class($subFlow) extends BatchFlow {
            public function prep(stdClass $shared): array {
                $params = [];
                $i = 1;
                foreach (array_keys($shared->input_data) as $key) {
                    $params[] = ['key' => $key, 'multiplier' => $i++];
                }
                return $params;
            }
        };

        $batchFlow->setParams(['shared' => $shared]);
        $batchFlow->run($shared);

        $expected = ['a' => 1, 'b' => 4, 'c' => 9];
        $this->assertEquals($expected, $shared->results);
    }
}