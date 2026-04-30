# Batch Processing

## BatchNode vs BatchFlow — Critical Distinction

| | BatchNode | BatchFlow |
|---|---|---|
| **`prep()` returns** | Array of **data items** to process | Array of **param dicts** to pass to sub-flow |
| **`exec()` receives** | One item at a time | (not called directly) |
| **`post()` receives** | Array of all results (`exec_res_list`) | Not used (sub-flow handles post) |
| **Items accessed via** | `$item` in `exec($item)` | `$this->params['key']` in child nodes |
| **Use case** | Process a list (e.g., chunk text, embed files) | Run a sub-flow per input (e.g., per-file pipeline) |

---

## BatchNode — Process Items in Sequence

`prep()` returns an array. Each element is passed to `exec()` individually. Results are collected into an array and passed to `post()`.

```php
use PocketFlow\BatchNode;
use PocketFlow\Flow;
use PocketFlow\SharedStore;

class ProcessItemsNode extends BatchNode {
    public function prep(SharedStore $shared): array {
        return $shared->items;  // e.g., ["item1", "item2", "item3"]
    }

    public function exec(mixed $item): mixed {
        return processOne($item);  // Process one at a time
    }

    public function post(SharedStore $shared, mixed $prepResult, mixed $allResults): ?string {
        $shared->results = $allResults;  // [result1, result2, result3]
        return null;
    }
}

// Usage
$node = new ProcessItemsNode();
$flow = new Flow($node);
$flow->run($shared);
```

**When to use:**
- Splitting large text into chunks for summarization
- Embedding multiple documents
- Processing a list of queries through the same LLM prompt
- **Any case where exec works on one data item at a time**

---

## BatchFlow — Run Sub-Flow per Parameter Set

`prep()` returns an array of **parameter dictionaries** (associative arrays). For each dict, the sub-flow is run with those params merged into `$this->params`. Child nodes access these via `$this->params['key']`.

```php
use PocketFlow\BatchFlow;
use PocketFlow\Node;
use PocketFlow\Flow;
use PocketFlow\SharedStore;

// Child node that reads filename from params (NOT from shared store)
class LoadFileNode extends Node {
    public function prep(SharedStore $shared): string {
        // Access filename from params — NOT from shared store
        return $this->params['filename'];
    }

    public function exec(mixed $filename): string {
        return file_get_contents($filename);
    }

    public function post(SharedStore $shared, mixed $p, mixed $content): ?string {
        $shared->currentContent = $content;
        return 'default';
    }
}

// Child node that also uses params for output indexing
class SummarizeNode extends Node {
    public function prep(SharedStore $shared): string {
        return $shared->currentContent;
    }

    public function exec(mixed $content): string {
        return callLlm("Summarize: {$content}");
    }

    public function post(SharedStore $shared, mixed $p, mixed $summary): ?string {
        // Use params to index the output
        $filename = $this->params['filename'];
        if (!isset($shared->summaries)) $shared->summaries = [];
        $shared->summaries[$filename] = $summary;
        return 'default';
    }
}

// The BatchFlow that provides params
class ProcessAllFilesBatchFlow extends BatchFlow {
    public function prep(SharedStore $shared): array {
        // Return PARAM dictionaries, NOT data items
        return array_map(fn($f) => ['filename' => $f], $shared->fileList);
    }
}

// Build the sub-flow (the per-file pipeline)
$loadNode = new LoadFileNode();
$summarizeNode = new SummarizeNode();
$loadNode->next($summarizeNode);
$subFlow = new Flow($loadNode);

// Wrap in BatchFlow
$batchFlow = new class($subFlow) extends ProcessAllFilesBatchFlow {
    public function prep(SharedStore $shared): array {
        return array_map(fn($f) => ['filename' => $f], $shared->fileList);
    }
};

$shared = new SharedStore();
$shared->fileList = ['doc1.txt', 'doc2.txt', 'doc3.txt'];
$batchFlow->run($shared);
```

**When to use:**
- Processing each file with a multi-step pipeline
- Running a search→analyze→report flow per query
- **Any case where each batch item needs a full sub-flow, not just a single exec()**

---

## Multi-Level Nesting

BatchFlows can be nested. Params cascade — the innermost node sees the merged params from ALL levels.

```php
// Outer batch: iterate directories
class DirectoryBatchFlow extends BatchFlow {
    public function prep(SharedStore $shared): array {
        return [['directory' => '/path/A'], ['directory' => '/path/B']];
    }
}

// Inner batch: iterate files within a directory
class FileBatchFlow extends BatchFlow {
    public function prep(SharedStore $shared): array {
        $dir = $this->params['directory'];  // From outer batch
        $files = glob("{$dir}/*.txt");
        return array_map(fn($f) => ['filename' => basename($f)], $files);
    }
}

// Processing node — sees BOTH directory and filename
class ProcessFileNode extends Node {
    public function prep(SharedStore $shared): string {
        // Merged params from ALL parent BatchFlows
        $dir = $this->params['directory'];
        $file = $this->params['filename'];
        return "{$dir}/{$file}";
    }

    public function exec(mixed $path): string {
        return callLlm("Analyze file: {$path}");
    }

    public function post(SharedStore $shared, mixed $p, mixed $result): ?string {
        $shared->results[$this->params['directory']][$this->params['filename']] = $result;
        return 'default';
    }
}

// Wire it up
$processNode = new ProcessFileNode();
$innerFlow = new FileBatchFlow($processNode);
$outerFlow = new DirectoryBatchFlow($innerFlow);
$outerFlow->run($shared);
// ProcessFileNode sees: ['directory' => '/path/A', 'filename' => 'doc.txt']
```

---

## Shared Store vs Params

Think of it like memory management:

| | Shared Store | Params |
|---|---|---|
| **Metaphor** | **Heap** (shared memory) | **Stack** (per-call arguments) |
| **Scope** | All nodes in a flow | Current node only |
| **Mutability** | Mutable (read/write) | Immutable during execution |
| **Set via** | `$shared->key = value` | `$flow->setParams([...])` or parent BatchFlow |
| **Use for** | Results, state, large content | Identifiers (filename, ID, index) |
| **Available in** | `prep()`, `post()` | `prep()`, `exec()`, `post()` via `$this->params` |

**Rule:** Use Shared Store for almost everything. Params are a convenience mechanism primarily for BatchFlow.
