<?php
declare(strict_types=1);

namespace PocketFlow\Tests;

use PHPUnit\Framework\TestCase;
use PocketFlow\Node;
use PocketFlow\BatchNode;
use PocketFlow\Flow;
use stdClass;

// --- Helper nodes for the Map-Reduce test pattern ---

/**
 * MAP Phase: Splits an array into chunks and sums each chunk.
 */
class ArrayChunkSumNode extends BatchNode {
    public function __construct(private int $chunkSize = 10) { parent::__construct(); }
    public function prep(stdClass $shared): array {
        return array_chunk($shared->input_array ?? [], $this->chunkSize);
    }
    public function exec(mixed $chunk): int {
        return array_sum($chunk);
    }
    public function post(stdClass $shared, mixed $p, mixed $execResult): ?string {
        $shared->chunk_sums = $execResult;
        return 'default';
    }
}

/**
 * REDUCE Phase: Aggregates the chunk sums into a final total.
 */
class SumReduceNode extends Node {
    public function prep(stdClass $shared): array {
        return $shared->chunk_sums ?? [];
    }
    public function exec(mixed $chunkSums): int {
        return array_sum($chunkSums);
    }
    public function post(stdClass $shared, mixed $p, mixed $execResult): ?string {
        $shared->total_sum = $execResult;
        return null;
    }
}

class BatchNodeTest extends TestCase
{
    private function runMapReducePipeline(array $inputArray, int $chunkSize): stdClass
    {
        $shared = new stdClass();
        $shared->input_array = $inputArray;

        $mapNode = new ArrayChunkSumNode($chunkSize);
        $reduceNode = new SumReduceNode();
        $mapNode->next($reduceNode);

        $pipeline = new Flow($mapNode);
        $pipeline->run($shared);

        return $shared;
    }

    public function testMapReduceSum()
    {
        $array = range(0, 99);
        $shared = $this->runMapReducePipeline($array, 10);
        $this->assertEquals(4950, $shared->total_sum);
    }

    public function testUnevenChunks()
    {
        $array = range(0, 24);
        $shared = $this->runMapReducePipeline($array, 10);
        $this->assertEquals([45, 145, 110], $shared->chunk_sums);
        $this->assertEquals(300, $shared->total_sum);
    }

    public function testEmptyArray()
    {
        $shared = $this->runMapReducePipeline([], 10);
        $this->assertEquals(0, $shared->total_sum);
    }
}