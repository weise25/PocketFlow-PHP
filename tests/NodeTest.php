<?php
namespace PocketFlow\Tests;

use PHPUnit\Framework\TestCase;
use PocketFlow\Node;
use stdClass;
use Throwable;

class NodeTest extends TestCase
{
    public function testNodeExecutesSuccessfully()
    {
        $shared = new stdClass();
        $shared->result = null;

        $node = new class extends Node {
            public function exec(mixed $prepResult): string {
                return "success";
            }
            public function post(stdClass $shared, mixed $prepResult, mixed $execResult): ?string {
                $shared->result = $execResult;
                return null;
            }
        };

        // We call the public `run` method
        $node->run($shared);

        $this->assertEquals("success", $shared->result);
    }

    public function testNodeRetriesOnFailureAndSucceeds()
    {
        $shared = new stdClass();
        $shared->result = null;
        $shared->attempts = 0;

        $node = new class(maxRetries: 3) extends Node {
            public function exec(mixed $prepResult): string {
                // Access shared object to track attempts, as class is recreated in a real flow
                $this->params['shared']->attempts++;
                if ($this->params['shared']->attempts < 3) {
                    throw new \Exception("Failed attempt");
                }
                return "success on attempt 3";
            }
            public function prep(stdClass $shared): mixed {
                // Pass shared object to exec via params for tracking
                $this->params['shared'] = $shared;
                return null;
            }
            public function post(stdClass $shared, mixed $prepResult, mixed $execResult): ?string {
                $shared->result = $execResult;
                return null;
            }
        };

        // Call the public `run` method
        $node->run($shared);

        $this->assertEquals(3, $shared->attempts);
        $this->assertEquals("success on attempt 3", $shared->result);
    }

    public function testNodeUsesFallbackAfterAllRetriesFail()
    {
        $shared = new stdClass();
        $shared->result = null;

        $node = new class(maxRetries: 2) extends Node {
            public function exec(mixed $prepResult): string {
                throw new \Exception("Always fails");
            }
            public function execFallback(mixed $prepResult, Throwable $e): mixed {
                // The fallback should return a value, not throw another exception
                // unless that is the desired behavior.
                return "fallback result";
            }
            public function post(stdClass $shared, mixed $prepResult, mixed $execResult): ?string {
                $shared->result = $execResult;
                return null;
            }
        };

        // Call the public `run` method
        $node->run($shared);

        // We assert that the fallback result was stored in the shared object
        $this->assertEquals("fallback result", $shared->result);
    }

    public function testNodeThrowsExceptionIfFallbackThrows()
    {
        // This test now checks if the exception from the fallback propagates up.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("All retries failed and fallback also failed");

        $node = new class(maxRetries: 2) extends Node {
            public function exec(mixed $prepResult): string {
                throw new \Exception("Always fails");
            }
            public function execFallback(mixed $prepResult, Throwable $e): mixed {
                // Now the fallback throws the exception we want to catch
                throw new \RuntimeException("All retries failed and fallback also failed", 0, $e);
            }
        };

        // The public `run` method should now throw the exception
        $node->run(new stdClass());
    }
}