<?php
namespace PocketFlow\Tests;

use PHPUnit\Framework\TestCase;
use PocketFlow\Node;
use PocketFlow\Flow;
use stdClass;

// --- Helper nodes for this test ---

class NumberNode extends Node {
    public function __construct(private int $number) { parent::__construct(); }
    public function exec(mixed $p): mixed {
        $this->params['shared']->current = $this->number;
        return null;
    }
}

class AddNode extends Node {
    public function __construct(private int $number) { parent::__construct(); }
    public function exec(mixed $p): mixed {
        $this->params['shared']->current += $this->number;
        return null;
    }
}

class MultiplyNode extends Node {
    public function __construct(private int $number) { parent::__construct(); }
    public function exec(mixed $p): mixed {
        $this->params['shared']->current *= $this->number;
        return null;
    }
}

// A node that returns a specific action to test branching.
class SignalNode extends Node {
    public function __construct(private string $signal = "default_signal") { parent::__construct(); }
    public function post(stdClass $shared, mixed $p, mixed $e): ?string {
        $shared->last_signal_emitted = $this->signal;
        return $this->signal;
    }
}

// A node that stores which path was taken.
class PathNode extends Node {
    public function __construct(private string $path_id) { parent::__construct(); }
    public function exec(mixed $p): mixed {
        $this->params['shared']->path_taken = $this->path_id;
        return null;
    }
}


// --- The actual test class ---

class FlowCompositionTest extends TestCase
{
    /**
     * Tests if a flow works as a simple node in another flow.
     */
public function testFlowAsNode()
{
    $shared = new stdClass();
    $shared->current = 0;

    // Build the chain before creating the flow.
    $startNode = new NumberNode(5);
    $startNode->next(new AddNode(10))->next(new MultiplyNode(2));

    // Create the inner flow with the already built chain.
    $innerFlow = new Flow($startNode);

    // Outer flow that has the inner flow as its start node.
    $outerFlow = new Flow($innerFlow);
    
    // We pass the $shared object via params so that the nodes can access it.
    $outerFlow->setParams(['shared' => $shared]);
    $outerFlow->run($shared);

    $this->assertEquals(30, $shared->current);
}

    /**
     * Tests a chain of two separate flows.
     */
    public function testChainingTwoFlows()
    {
        $shared = new stdClass();
        $shared->current = 0;

        // Flow 1: 10 + 10 = 20
        $flow1_start = new NumberNode(10);
        $flow1_start->next(new AddNode(10));
        $flow1 = new Flow($flow1_start);

        // Flow 2: * 2
        $flow2 = new Flow(new MultiplyNode(2));

        // Chain the flows together.
        $flow1->next($flow2);

        // Outer wrapper flow for execution.
        $mainFlow = new Flow($flow1);
        $mainFlow->setParams(['shared' => $shared]);
        $mainFlow->run($shared);

        $this->assertEquals(40, $shared->current); // (10 + 10) * 2
    }

    /**
     * The most important test: An outer flow branches based on the
     * action returned by the last node of an inner flow.
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

        // 2. Target nodes for the branches of the outer flow.
        $path_a_node = new PathNode("A");
        $path_b_node = new PathNode("B");

        // 3. Define the outer flow, which starts with the inner flow.
        $outerFlow = new Flow($innerFlow);

        // 4. Define the branches originating from the inner flow.
        $innerFlow->on("inner_done")->next($path_b_node);  // This path should be taken.
        $innerFlow->on("other_action")->next($path_a_node); // Not this one.

        // 5. Execute the outer flow.
        $outerFlow->setParams(['shared' => $shared]);
        $last_action_outer = $outerFlow->run($shared);

        // 6. Check the results.
        $this->assertEquals(100, $shared->current); // From the NumberNode in the inner flow.
        $this->assertEquals("inner_done", $shared->last_signal_emitted); // From the SignalNode.
        $this->assertEquals("B", $shared->path_taken); // The correct path was taken.
        $this->assertNull($last_action_outer); // The last node (PathNode) returns null.
    }
}