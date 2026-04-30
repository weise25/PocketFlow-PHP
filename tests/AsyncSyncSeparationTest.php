<?php
declare(strict_types=1);

namespace PocketFlow\Tests;

use PHPUnit\Framework\TestCase;
use PocketFlow\AsyncNode;
use PocketFlow\Flow;
use PocketFlow\SharedStore;
use React\Promise\PromiseInterface;
use function React\Async\async;

class AsyncSyncSeparationTest extends TestCase
{
    public function testSyncFlowWithAsyncNodeThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Synchronous Flow cannot contain async nodes");

        $asyncNode = new class extends AsyncNode {
            public function execAsync(mixed $p): PromiseInterface {
                return async(fn() => null)();
            }
        };

        $flow = new Flow($asyncNode);
        $flow->run(new SharedStore());
    }
}
