<?php
declare(strict_types=1);

namespace PocketFlow\Tests;

use PHPUnit\Framework\TestCase;
use PocketFlow\Node;
use PocketFlow\Flow;
use stdClass;

class FlowTest extends TestCase
{
    /**
     * Tests that a simple linear flow executes nodes in the correct order.
     */
    public function testLinearFlowExecutesInOrder()
    {
        $shared = new stdClass();
        $shared->execution_order = [];

        $nodeA = new class extends Node {
            public function post(stdClass $shared, mixed $p, mixed $e): ?string {
                $shared->execution_order[] = 'A';
                return 'default';
            }
        };
        $nodeB = new class extends Node {
            public function post(stdClass $shared, mixed $p, mixed $e): ?string {
                $shared->execution_order[] = 'B';
                return null;
            }
        };

        $nodeA->next($nodeB);
        $flow = new Flow($nodeA);
        $flow->run($shared);

        $this->assertEquals(['A', 'B'], $shared->execution_order);
    }

    /**
     * Tests that a flow with conditional branches follows the correct path based on the returned action.
     */
    public function testBranchingFlowSelectsCorrectPath()
    {
        $shared = new stdClass();
        $shared->execution_order = [];

        $decideNode = new class extends Node {
            public function post(stdClass $shared, mixed $p, mixed $e): ?string {
                $shared->execution_order[] = 'decide';
                return 'path_b'; // Explicitly choose path B
            }
        };
        $nodeA = new class extends Node {
            public function post(stdClass $shared, mixed $p, mixed $e): ?string {
                $shared->execution_order[] = 'A';
                return null;
            }
        };
        $nodeB = new class extends Node {
            public function post(stdClass $shared, mixed $p, mixed $e): ?string {
                $shared->execution_order[] = 'B';
                return null;
            }
        };

        $decideNode->on('path_a')->next($nodeA);
        $decideNode->on('path_b')->next($nodeB);

        $flow = new Flow($decideNode);
        $flow->run($shared);

        $this->assertEquals(['decide', 'B'], $shared->execution_order);
        $this->assertNotContains('A', $shared->execution_order);
    }

    /**
     * Tests that a flow correctly terminates when a node returns an action with no defined successor.
     */
    public function testFlowEndsWhenActionHasNoSuccessor()
    {
        $shared = new stdClass();
        $shared->execution_order = [];

        $nodeA = new class extends Node {
            public function post(stdClass $shared, mixed $p, mixed $e): ?string {
                $shared->execution_order[] = 'A';
                return 'unknown_action'; // This action has no successor
            }
        };
        $nodeB = new class extends Node {
            public function post(stdClass $shared, mixed $p, mixed $e): ?string {
                $shared->execution_order[] = 'B';
                return null;
            }
        };

        $nodeA->next($nodeB); // Only for 'default' action
        $flow = new Flow($nodeA);
        
        // Suppress the expected E_USER_WARNING from trigger_error.
        @$flow->run($shared);

        // The flow should stop after Node A.
        $this->assertEquals(['A'], $shared->execution_order);
    }

    /**
     * Tests a cyclic flow structure to ensure it loops correctly and terminates on a condition.
     */
    public function testCyclicFlowExecutesUntilConditionIsMet()
    {
        $shared = new stdClass();
        $shared->current_value = 10;

        $checkNode = new class extends Node {
            public function post(stdClass $shared, mixed $p, mixed $e): ?string {
                return $shared->current_value > 0 ? 'is_positive' : 'is_negative_or_zero';
            }
        };
        $subtractNode = new class extends Node {
            public function post(stdClass $shared, mixed $p, mixed $e): ?string {
                $shared->current_value -= 3;
                return null;
            }
        };
        $endNode = new class extends Node {
            public function post(stdClass $shared, mixed $p, mixed $e): ?string {
                $shared->final_signal = "cycle_done";
                return null;
            }
        };

        // Flow definition:
        // check -> (if positive) -> subtract -> check (loop)
        // check -> (if negative or zero) -> end
        $checkNode->on('is_positive')->next($subtractNode);
        $checkNode->on('is_negative_or_zero')->next($endNode);
        $subtractNode->next($checkNode); // The loop connection

        $flow = new Flow($checkNode);
        $flow->run($shared);

        // Expected sequence: 10 -> 7 -> 4 -> 1 -> -2 (stops)
        $this->assertEquals(-2, $shared->current_value);
        $this->assertEquals("cycle_done", $shared->final_signal);
    }
}