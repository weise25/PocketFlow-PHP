<?php
declare(strict_types=1);

namespace PocketFlow\Tests;

use PHPUnit\Framework\TestCase;
use PocketFlow\Node;
use PocketFlow\SharedStore;
use Throwable;

class NodeTest extends TestCase
{
    /**
     * Tests that a simple node executes its prep -> exec -> post lifecycle correctly.
     */
    public function testNodeExecutesSuccessfully()
    {
        $shared = new SharedStore();
        $shared->result = null;

        $node = new class extends Node {
            public function exec(mixed $prepResult): string {
                return "success";
            }
            public function post(SharedStore $shared, mixed $prepResult, mixed $execResult): ?string {
                $shared->result = $execResult;
                return null;
            }
        };

        $node->run($shared);
        $this->assertEquals("success", $shared->result);
    }

    /**
     * Tests that the retry mechanism correctly re-executes the exec() method upon failure.
     */
    public function testNodeRetriesOnFailureAndSucceeds()
    {
        $shared = new SharedStore();
        $shared->result = null;
        $shared->attempts = 0;

        $node = new class(maxRetries: 3) extends Node {
            public function exec(mixed $prepResult): string {
                // NOTE: This test intentionally mutates state passed from prep() inside exec()
                // to isolate and verify the retry mechanism without needing a full Flow.
                // This is a violation of the "pure exec" rule for the sake of a focused unit test.
                $prepResult->attempts++;
                if ($prepResult->attempts < 3) {
                    throw new \Exception("Failed attempt");
                }
                return "success on attempt 3";
            }
            public function prep(SharedStore $shared): SharedStore {
                return $shared;
            }
            public function post(SharedStore $shared, mixed $prepResult, mixed $execResult): ?string {
                $shared->result = $execResult;
                return null;
            }
        };

        $node->run($shared);
        $this->assertEquals(3, $shared->attempts);
        $this->assertEquals("success on attempt 3", $shared->result);
    }

    /**
     * Tests that the execFallback() method is called after all retries have been exhausted.
     */
    public function testNodeUsesFallbackAfterAllRetriesFail()
    {
        $shared = new SharedStore();
        $shared->result = null;

        $node = new class(maxRetries: 2) extends Node {
            public function exec(mixed $prepResult): string {
                throw new \Exception("Always fails");
            }
            public function execFallback(mixed $prepResult, Throwable $e): mixed {
                return "fallback result";
            }
            public function post(SharedStore $shared, mixed $prepResult, mixed $execResult): ?string {
                $shared->result = $execResult;
                return null;
            }
        };

        $node->run($shared);
        $this->assertEquals("fallback result", $shared->result);
    }

    /**
     * Tests that an exception thrown from within execFallback() propagates up correctly.
     */
    public function testNodeThrowsExceptionIfFallbackThrows()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("All retries failed and fallback also failed");

        $node = new class(maxRetries: 2) extends Node {
            public function exec(mixed $prepResult): string {
                throw new \Exception("Always fails");
            }
            public function execFallback(mixed $prepResult, Throwable $e): mixed {
                throw new \RuntimeException("All retries failed and fallback also failed", 0, $e);
            }
        };

        $node->run(new SharedStore());
    }

    /**
     * Tests that attempting to overwrite a successor throws a LogicException.
     */
    public function testOverwriteSuccessorThrowsException(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("overwrite existing successor");

        $node = new class extends Node {};
        $target1 = new class extends Node {};
        $target2 = new class extends Node {};
        $node->next($target1, 'test_action');
        $node->next($target2, 'test_action'); // should throw
    }

    /**
     * Tests that calling run() on a node with successors throws a RuntimeException.
     */
    public function testRunWithSuccessorsThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Cannot run a node that has successors directly");

        $node = new class extends Node {};
        $node->next(new class extends Node {});
        $node->run(new SharedStore());
    }
}
