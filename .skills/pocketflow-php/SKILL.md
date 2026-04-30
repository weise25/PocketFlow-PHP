---
name: pocketflow-php
description: Use when building PHP applications with PocketFlow-PHP — the minimalist LLM framework for agentic coding. Provides Node/Flow graph orchestration, retry/fallback mechanisms, async ReactPHP integration, and SharedStore patterns. Trigger whenever the user mentions PocketFlow, PocketFlow-PHP, Node, Flow, BatchNode, BatchFlow, AsyncNode, AsyncFlow, SharedStore, building LLM workflows, AI agent pipelines, or any graph-based PHP applications. Also trigger when the user asks about agentic coding, LLM orchestration in PHP, or wants to create a flow-based application.
---

# PocketFlow-PHP

## Agentic Coding: Humans Design, Agents Code

> Read this **VERY, VERY** carefully! This is the most important section. Throughout development, always (1) start with a small and simple solution, (2) design at a high level (`docs/design.md`) before implementation, and (3) frequently ask humans for feedback and clarification.

### The 8-Step Process

| Step | Human | AI | Notes |
|---|---|---|---|
| 1. Requirements | ★★★ | ★☆☆ | Humans understand requirements and context |
| 2. Flow Design | ★★☆ | ★★☆ | Humans specify high-level design, AI fills in details |
| 3. Utilities | ★★☆ | ★★☆ | Humans provide external APIs, AI helps implement |
| 4. Data | ★☆☆ | ★★★ | AI designs the SharedStore schema, humans verify |
| 5. Node Design | ★☆☆ | ★★★ | AI designs nodes based on the flow |
| 6. Implementation | ★☆☆ | ★★★ | AI implements the flow |
| 7. Optimization | ★★☆ | ★★☆ | Humans evaluate, AI optimizes |
| 8. Reliability | ★☆☆ | ★★★ | AI writes tests and handles corner cases |

**Step 1 — Requirements:** Understand what the user wants. AI systems excel at routine tasks (form filling, email replies) and creative tasks with well-defined inputs (slides, SQL). They struggle with ambiguous problems requiring complex decision-making. Keep it user-centric — describe the problem from the user's perspective.

**Step 2 — Flow Design:** Identify the design pattern before writing code:
- **Workflow** (linear chain): Simple sequence of nodes
- **Agent** (decision loop): Node decides actions, branches to different paths, loops back
- **Map-Reduce** (parallel processing): Split work across BatchNode/AsyncParallelBatchNode, then aggregate
- **RAG** (retrieval-augmented generation): Embed and retrieve relevant context

Draw the flow as a Mermaid diagram. If humans can't specify the flow, AI agents can't automate it.

**Step 3 — Utilities:** External functions are the "body" to the AI system's "brain":
- Reading inputs (files, APIs, user input)
- Writing outputs (PDF, email, reports)
- External tools (LLM calls, web search, databases)
- **LLM-based tasks** (summarization, analysis) are NOT utilities — they're core node logic
- Each utility gets its own file in `utils/`, with a `callLlm()` style function
- Avoid `try/catch` in utilities — let Node's built-in retry mechanism handle failures

**Step 4 — Data Design:** The SharedStore is the data contract all nodes agree on. Use nested associative arrays. Avoid redundancy — use references or foreign keys where possible.

```php
$shared = new SharedStore();
$shared->user = ['id' => 'user123', 'context' => ['weather' => 'sunny']];
$shared->results = [];
```

**Step 5 — Node Design:** For each node, describe at a high level:
- Type: Regular, Batch, or Async
- prep(): What data it reads from SharedStore
- exec(): What it does (which utility it calls)
- post(): What it writes back to SharedStore, and which action it returns

**Step 6 — Implementation:** Keep it simple. Fail fast — use Node's retry/fallback. Add logging.

**Step 7 — Optimization:** Use intuition first, then iterate. Common improvements:
- Prompt engineering (clear instructions, examples)
- Flow redesign (break tasks down, add agentic decisions)
- In-context learning (provide examples for hard-to-specify tasks)

**Step 8 — Reliability:** Increase `maxRetries` and `wait` on critical nodes. Add self-evaluation nodes (LLM reviews output). Maintain logs.

---

## Project File Structure

```
my_project/
├── main.php              # Entry point
├── nodes.php             # All node class definitions
├── flow.php              # Flow creation function
├── utils/
│   ├── callLlm.php       # LLM API calls
│   └── searchWeb.php     # Web search utilities
├── docs/
│   └── design.md         # High-level design (no code)
├── composer.json         # Dependencies
└── .env                  # API keys (gitignored)
```

## composer.json

```json
{
    "require": {
        "php": ">=8.3",
        "weise25/pocketflow-php": "^0.2"
    }
}
```

---

## Core Classes Reference

### BaseNode (abstract)

All nodes extend this. Key methods to override:

```php
// Prepare data — read from SharedStore, return data for exec()
public function prep(SharedStore $shared): mixed { return null; }

// Execute logic — core processing
public function exec(mixed $prepResult): mixed { return null; }

// Process results — write to SharedStore, return action string
public function post(SharedStore $shared, mixed $prepResult, mixed $execResult): ?string { return null; }
```

Graph construction:
```php
$nodeA->next($nodeB);                    // Default action → nodeB
$nodeA->next($nodeB, 'success');         // Action 'success' → nodeB
$nodeA->on('success')->next($nodeB);     // Fluent syntax
$nodeA->on('end')->next(null);           // null = stop flow on this action
```

Properties:
- `$this->params` — associative array of runtime parameters
- `$this->successors` — map of action strings to successor nodes

### Node (standard, with retry)

```php
class MyNode extends Node
{
    // Constructor sets retry behavior
    // new MyNode(maxRetries: 3, wait: 2)  → 3 attempts, 2s between retries

    public function execFallback(mixed $prepResult, Throwable $e): mixed
    {
        // Called after all retries exhausted. Override to provide fallback.
        // Default: re-throws $e
        return 'fallback value';
    }
}
```

### BatchNode (Map pattern)

Processes arrays of items sequentially through exec():

```php
class ProcessItemsNode extends BatchNode
{
    public function prep(SharedStore $shared): array
    {
        return $shared->items;  // Return array of items
    }

    public function exec(mixed $item): mixed
    {
        // Process one item — called once per array element
        return processItem($item);
    }

    public function post(SharedStore $shared, mixed $p, mixed $results): ?string
    {
        $shared->results = $results;  // Array of all results
        return null;
    }
}
```

### AsyncNode (ReactPHP promises)

```php
use React\Promise\PromiseInterface;
use function React\Async\async;

class MyAsyncNode extends AsyncNode
{
    public function prepAsync(SharedStore $shared): PromiseInterface
    {
        return async(fn() => $shared->data)();
    }

    public function execAsync(mixed $prepResult): PromiseInterface
    {
        return async(function() use ($prepResult) {
            // Async work here (API calls, timers, etc.)
            return doAsyncWork($prepResult);
        })();
    }

    public function postAsync(SharedStore $shared, mixed $p, mixed $e): PromiseInterface
    {
        return async(function() use ($shared, $e) {
            $shared->output = $e;
            return 'default';
        })();
    }

    public function execFallbackAsync(mixed $prepResult, Throwable $e): PromiseInterface
    {
        return async(fn() => 'fallback')();
    }
}
```

Async nodes use the same `maxRetries`/`wait` retry mechanism as sync nodes, but via async sleep (`React\Promise\Timer\sleep`).

### AsyncBatchNode & AsyncParallelBatchNode

- `AsyncBatchNode` — processes items sequentially (one at a time), each returning a Promise
- `AsyncParallelBatchNode` — processes items concurrently using `React\Promise\all`, all Promises run in parallel

### Flow (sync orchestration)

```php
$flow = new Flow($startNode);
$flow->run($shared);

// Alternative: pass start node via constructor
$flow = new Flow();
$flow->start($startNode);
```

Flow's `post()` passes through the last action unchanged. Override for custom post-processing.

### AsyncFlow (async orchestration)

```php
$flow = new AsyncFlow($startNode);
await($flow->runAsync($shared));
```

Handles both sync and async nodes. Sync nodes run normally; async nodes are awaited. Must use `await()` at the call site since `runAsync()` returns a `PromiseInterface`.

### BatchFlow & AsyncBatchFlow & AsyncParallelBatchFlow

Run a sub-flow for each parameter set returned by `prep()`:
- `BatchFlow` — sequential sync
- `AsyncBatchFlow` — sequential async (one sub-flow at a time)
- `AsyncParallelBatchFlow` — concurrent async (all sub-flows run in parallel via `React\Promise\all`)

### SharedStore

Dynamic properties class — assign anything:
```php
$shared = new SharedStore();
$shared->query = 'What is PHP?';
$shared->results = [];
$shared->metadata = ['source' => 'user'];
```

### AsyncRunnable (interface)

Marker interface — any node implementing this can be used in `AsyncFlow`. `AsyncNode` and `AsyncFlow` implement it.

---

## Code Generation Patterns

### Pattern 1: Simple Linear Flow

```php
// nodes.php
use PocketFlow\Node;
use PocketFlow\SharedStore;

class FetchNode extends Node {
    public function exec(mixed $p): mixed {
        return callLlm("Generate a greeting");
    }
    public function post(SharedStore $shared, mixed $p, mixed $e): ?string {
        $shared->greeting = $e;
        return 'default';
    }
}

class DisplayNode extends Node {
    public function prep(SharedStore $shared): mixed {
        return $shared->greeting;
    }
    public function exec(mixed $greeting): mixed {
        echo $greeting . PHP_EOL;
        return null;
    }
}

// flow.php
use PocketFlow\Flow;

function createFlow(): Flow {
    $fetch = new FetchNode();
    $display = new DisplayNode();
    $fetch->next($display);
    return new Flow($fetch);
}

// main.php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/flow.php';

use PocketFlow\SharedStore;

$shared = new SharedStore();
createFlow()->run($shared);
```

### Pattern 2: Agent Loop (decision + branching + loop-back)

```php
class DecideNode extends Node {
    public function post(SharedStore $shared, mixed $p, mixed $e): ?string {
        if ($shared->shouldContinue) return 'continue';
        return 'stop';
    }
}

class ActionNode extends Node { /* ... */ }

// flow.php
$decide = new DecideNode();
$action = new ActionNode();

$decide->on('continue')->next($action);
$decide->on('stop')->next(null);  // Explicitly stop
$action->next($decide);           // Loop back

return new Flow($decide);
```

### Pattern 3: Map-Reduce with BatchNode

```php
class MapNode extends BatchNode {
    public function prep(SharedStore $shared): array {
        return array_chunk($shared->input, 10);
    }
    public function exec(mixed $chunk): mixed {
        return array_sum($chunk);
    }
}

class ReduceNode extends Node {
    public function prep(SharedStore $shared): mixed {
        return $shared->chunkResults;
    }
    public function exec(mixed $results): mixed {
        return array_sum($results);
    }
    public function post(SharedStore $shared, mixed $p, mixed $e): ?string {
        $shared->total = $e;
        return null;
    }
}
```

---

## Best Practices

- **Always start with `main.php`** as the entry point. It initializes SharedStore, creates the flow, and runs it.
- **Keep nodes focused** — each node should do one thing. prep reads, exec processes, post writes + routes.
- **Use retries, not try/catch in exec()** — let the Node's built-in retry mechanism handle transient failures. Set `maxRetries` and `wait` on the constructor.
- **Don't catch exceptions in utility functions** — let them bubble up so the Node can retry.
- **Use `on()->next()` for branching**, `$node->next()` for simple linear flow.
- **Return `null` from post() to stop the flow**, return a string action to route to a successor.
- **Use SharedStore for all inter-node communication** — never use global variables between nodes.
- **Always write `declare(strict_types=1)`** at the top of every PHP file.
- **Never expose API keys in code** — use `.env` files with `vlucas/phpdotenv` or `$_ENV`.
- **For async flows**, read the ReactPHP skill for detailed async patterns. Use `async()` to wrap async work and `await()` to resolve Promises.
- **Flow extends BaseNode** — flows can be nested inside other flows as sub-flows.
- **When using BatchFlow/AsyncBatchFlow**, pass the sub-flow into the constructor: `new class($subFlow) extends BatchFlow { ... }`.

## PHP Idioms

Use modern PHP 8.3+ features to write clean, type-safe code:

### match() for Action Routing
```php
// Cleaner than if/elseif chains
public function post(SharedStore $shared, mixed $p, mixed $decision): ?string {
    return match ($decision) {
        'approved'       => 'payment',
        'needs_revision' => 'edit',
        'rejected'       => null,       // null = stop flow
        default          => 'default',
    };
}
```

### Array Destructuring
```php
// prep() returns multiple values cleanly
[$query, $history] = $shared->{['query', 'searchHistory']};
// Or:
public function exec(mixed $p): string {
    [$question, $context] = $p;
    return callLlm("Q: {$question}\nC: {$context}");
}
```

### Native String Functions (PHP 8.0+)
```php
str_contains($text, 'error');      // instead of strpos() !== false
str_starts_with($text, 'Error:');  // instead of substr() ===
str_ends_with($text, '.txt');      // instead of substr(-4) ===
```

### Strict Typing + Named Arguments
```php
declare(strict_types=1);  // Always at top of file

$node = new SummarizeNode(
    maxRetries: 3,   // Named — self-documenting
    wait: 5,          // Seconds between retries
);
```

### Validation in exec() (triggers automatic retry)
```php
class ParseNode extends Node {
    public function exec(mixed $response): array {
        $yaml = Yaml::parse($response);
        if (!isset($yaml['action'])) {
            throw new \RuntimeException('Missing action key');
        }
        return $yaml;  // Valid → Node won't retry
    }
}
```

## Reference Files

For deeper guidance with full PHP code examples, read these as needed:

- **`references/design-patterns.md`** — Complete PHP implementations of Agent, Workflow, RAG, Map-Reduce, Multi-Agent, and Structured Output patterns. Read this when designing a flow with a specific pattern in mind.
- **`references/batch-processing.md`** — Critical distinction between BatchNode (processes data items) and BatchFlow (runs sub-flow per param set). Covers params mechanism, multi-level nesting, and SharedStore vs Params. Read this before using any Batch class.
