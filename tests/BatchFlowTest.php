<?php
declare(strict_types=1);

namespace PocketFlow\Tests;

use PHPUnit\Framework\TestCase;
use PocketFlow\Node;
use PocketFlow\Flow;
use PocketFlow\BatchFlow;
use stdClass;
use ValueError;

class BatchFlowTest extends TestCase
{
    /**
     * Tests that a BatchFlow correctly executes a sub-flow for each parameter set.
     */
    public function testBatchFlowProcessesAllParameterSets()
    {
        $shared = new stdClass();
        $shared->input_data = ['a' => 1, 'b' => 2, 'c' => 3];
        $shared->results = [];

        $processItemNode = new class extends Node {
            public function post(stdClass $shared, mixed $p, mixed $e): ?string {
                $key = $this->params['key'];
                $shared->results[$key] = $shared->input_data[$key] * 2;
                return null;
            }
        };

        $subFlow = new Flow($processItemNode);
        $batchFlow = new class($subFlow) extends BatchFlow {
            public function prep(stdClass $shared): array {
                return array_map(fn($k) => ['key' => $k], array_keys($shared->input_data));
            }
        };

        $batchFlow->run($shared);
        $this->assertEquals(['a' => 2, 'b' => 4, 'c' => 6], $shared->results);
    }

    /**
     * Tests that an exception thrown inside a sub-flow correctly propagates up.
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
}