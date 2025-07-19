<?php
declare(strict_types=1);

namespace PocketFlow\Tests;

use PHPUnit\Framework\TestCase;
use PocketFlow\Node;
use PocketFlow\Flow;
use stdClass;

// --- Helper nodes for this test suite ---

class NumberNode extends Node {
    public function __construct(private int $number) { parent::__construct(); }
    public function post(stdClass $shared, mixed $p, mixed $e): ?string {
        $shared->current = $this->number;
        return null;
    }
}
class AddNode extends Node {
    public function __construct(private int $number) { parent::__construct(); }
    public function post(stdClass $shared, mixed $p, mixed $e): ?string {
        $shared->current += $this->number;
        return null;
    }
}
class MultiplyNode extends Node {
    public function __construct(private int $number) { parent::__construct(); }
    public function post(stdClass $shared, mixed $p, mixed $e): ?string {
        $shared->current *= $this->number;
        return null;
    }
}
// A node that returns a specific action to test branching from a sub-flow.
class SignalNode extends Node {
    public function __construct(private string $signal = "default_signal") { parent::__construct(); }
    public function post(stdClass $shared, mixed $p, mixed $e): ?string {
        $shared->last_signal_emitted = $this->signal;
        return $this->signal;
    }
}
// A node that stores which path was taken in the main flow.
class PathNode extends Node {
    public function __construct(private string $path_id) { parent::__construct(); }
    public function post(stdClass $shared, mixed $p, mixed $e): ?string {
        $shared->path_taken = $this->path_id;
        return null;
    }
}

class FlowCompositionTest extends TestCase
{
    /**
     * Tests that a Flow can be used as a single node within another Flow.
     */
    public function testFlowAsNode()
    {
        $shared = new stdClass();
        $shared->current = 0;

        $startNode = new NumberNode(5);
        $startNode->next(new AddNode(10))->next(new MultiplyNode(2));
        $innerFlow = new Flow($startNode);
        $outerFlow = new Flow($innerFlow);
        
        $outerFlow->run($shared);
        $this->assertEquals(30, $shared->current);
    }

    /**
     * Tests that two separate flows can be chained together sequentially.
     */
    public function testChainingTwoFlows()
    {
        $shared = new stdClass();
        $shared->current = 0;

        // Flow 1: 10 + 10 = 20
        $flow1_start = new NumberNode(10);
        $flow1_start->next(new AddNode(10));
        $flow1 = new Flow($flow1_start);

        // Flow 2: result * 2
        $flow2 = new Flow(new MultiplyNode(2));

        // Chain the flows together.
        $flow1->next($flow2);
        $mainFlow = new Flow($flow1);
        
        $mainFlow->run($shared);
        $this->assertEquals(40, $shared->current); // (10 + 10) * 2
    }

    /**
     * Tests that an outer flow can branch based on the action returned by an inner flow.
     */
    public function testCompositionWithActionPropagation()
    {
        $shared = new stdClass();
        $shared->current = 0;

        // 1. Inner flow that ends with a SignalNode returning "inner_done".
        $inner_start_node = new NumberNode(100);
        $inner_end_node = new SignalNode("inner_done");
        $inner_start_node->next($inner_end_node);
        $innerFlow = new Flow($inner_start_node);

        // 2. Target nodes for the outer flow's branches.
        $path_a_node = new PathNode("A");
        $path_b_node = new PathNode("B");

        // 3. Define the outer flow, starting with the inner flow.
        $outerFlow = new Flow($innerFlow);
        $innerFlow->on("inner_done")->next($path_b_node);
        $innerFlow->on("other_action")->next($path_a_node);

        $last_action_outer = $outerFlow->run($shared);

        $this->assertEquals(100, $shared->current);
        $this->assertEquals("inner_done", $shared->last_signal_emitted);
        $this->assertEquals("B", $shared->path_taken);
        $this->assertNull($last_action_outer);
    }
}