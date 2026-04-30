# Design Patterns

## Table of Contents
1. [Agent](#1-agent) — autonomous decision-making loop
2. [Workflow](#2-workflow) — sequential task decomposition
3. [RAG](#3-rag) — retrieval-augmented generation (2-stage)
4. [Map-Reduce](#4-map-reduce) — split + process + combine
5. [Multi-Agent](#5-multi-agent) — concurrent agents with message queues
6. [Structured Output](#6-structured-output) — enforcing output format from LLMs

---

## 1. Agent

An **Agent** is a decision loop: a node decides an action, branches to a handler, then loops back. Great for research assistants, chatbots, and tool-using systems.

```php
use PocketFlow\Node;
use PocketFlow\Flow;
use PocketFlow\SharedStore;

// Decision node — evaluates context and picks an action
class DecideActionNode extends Node {
    public function prep(SharedStore $shared): array {
        return [
            'query'        => $shared->query,
            'searchHistory' => $shared->searchHistory ?? [],
        ];
    }

    public function exec(mixed $p): array {
        $prompt = "Query: {$p['query']}\nSearch history: " . implode("\n", $p['searchHistory']) .
                  "\n\nAction: 'search' or 'answer'. Reply YAML:\n```yaml\naction: search/answer\n```";
        $response = callLlm($prompt);
        // Parse YAML from response
        preg_match('/action:\s*(.+)/', $response, $m);
        return ['action' => trim($m[1] ?? 'answer')];
    }

    public function post(SharedStore $shared, mixed $p, mixed $decision): ?string {
        if ($decision['action'] === 'search') {
            $shared->searchTerm = $shared->query;
        }
        return $decision['action'];
    }
}

// Search node — calls external search API
class SearchWebNode extends Node {
    public function prep(SharedStore $shared): string {
        return $shared->searchTerm;
    }
    public function exec(mixed $term): string {
        return searchWeb($term);
    }
    public function post(SharedStore $shared, mixed $p, mixed $result): ?string {
        $shared->searchHistory[] = ['term' => $shared->searchTerm, 'result' => $result];
        return 'decide';  // Loop back to decision
    }
}

// Answer node — generates final answer
class AnswerNode extends Node {
    public function prep(SharedStore $shared): array {
        return [$shared->query, $shared->searchHistory ?? []];
    }
    public function exec(mixed $p): string {
        return callLlm("Answer: {$p[0]}\nContext: " . json_encode($p[1]));
    }
    public function post(SharedStore $shared, mixed $p, mixed $answer): ?string {
        echo "Answer: {$answer}\n";
        $shared->answer = $answer;
        return null;  // Stop
    }
}

// Flow
$decide = new DecideActionNode();
$search = new SearchWebNode();
$answer = new AnswerNode();

$decide->on('search')->next($search);
$decide->on('answer')->next($answer);
$search->on('decide')->next($decide);  // Loop back

$flow = new Flow($decide);
$shared = new SharedStore();
$shared->query = 'Who won the Nobel Prize in Physics 2024?';
$flow->run($shared);
```

**Key principles:**
- Provision relevant, minimal context (avoid lost-in-the-middle)
- Provide unambiguous, non-overlapping action space
- Use incremental/overview-zoom-in feeding of content
- Enable parameterized actions for flexibility

---

## 2. Workflow

**Task Decomposition**: break complex tasks into a linear chain of focused nodes.

```php
use PocketFlow\Node;
use PocketFlow\Flow;
use PocketFlow\SharedStore;

class GenerateOutlineNode extends Node {
    public function prep(SharedStore $shared): string { return $shared->topic; }
    public function exec(mixed $topic): string { return callLlm("Create outline for: {$topic}"); }
    public function post(SharedStore $shared, mixed $p, mixed $e): ?string {
        $shared->outline = $e;
        return 'default';
    }
}

class WriteSectionNode extends Node {
    public function prep(SharedStore $shared): string { return $shared->outline; }
    public function exec(mixed $outline): string { return callLlm("Write content: {$outline}"); }
    public function post(SharedStore $shared, mixed $p, mixed $e): ?string {
        $shared->draft = $e;
        return 'default';
    }
}

class ReviewNode extends Node {
    public function prep(SharedStore $shared): string { return $shared->draft; }
    public function exec(mixed $draft): string { return callLlm("Review and improve: {$draft}"); }
    public function post(SharedStore $shared, mixed $p, mixed $e): ?string {
        $shared->final = $e;
        return null;
    }
}

$outlineNode = new GenerateOutlineNode();
$writeNode   = new WriteSectionNode();
$reviewNode  = new ReviewNode();

$outlineNode->next($writeNode);
$writeNode->next($reviewNode);

$flow = new Flow($outlineNode);
$shared = new SharedStore();
$shared->topic = 'AI Safety';
$flow->run($shared);
echo $shared->final;
```

**Rule of thumb:** Not too coarse (confuses one LLM call), not too granular (loses context). For complex branching, use Agent pattern instead.

---

## 3. RAG

**Two-stage pipeline**: Offline indexing + Online query/answer.

### Stage 1: Offline Indexing (BatchNode chain)

```php
use PocketFlow\BatchNode;
use PocketFlow\Node;
use PocketFlow\Flow;

class ChunkDocsNode extends BatchNode {
    public function prep(SharedStore $shared): array {
        return $shared->files;  // ["doc1.txt", "doc2.txt"]
    }

    public function exec(mixed $filepath): array {
        $text = file_get_contents($filepath);
        return str_split($text, 100);  // chunks of 100 chars
    }

    public function post(SharedStore $shared, mixed $p, mixed $results): ?string {
        $allChunks = array_merge(...$results);
        $shared->allChunks = $allChunks;
        return 'default';
    }
}

class EmbedDocsNode extends BatchNode {
    public function prep(SharedStore $shared): array { return $shared->allChunks; }
    public function exec(mixed $chunk): array { return getEmbedding($chunk); }
    public function post(SharedStore $shared, mixed $p, mixed $results): ?string {
        $shared->allEmbeds = $results;
        return 'default';
    }
}

class StoreIndexNode extends Node {
    public function prep(SharedStore $shared): array { return $shared->allEmbeds; }
    public function exec(mixed $embeds): mixed {
        return createVectorIndex($embeds);
    }
    public function post(SharedStore $shared, mixed $p, mixed $index): ?string {
        $shared->index = $index;
        return null;
    }
}

$chunkNode = new ChunkDocsNode();
$embedNode = new EmbedDocsNode();
$storeNode = new StoreIndexNode();
$chunkNode->next($embedNode)->next($storeNode);

$offlineFlow = new Flow($chunkNode);
```

### Stage 2: Online Query

```php
class EmbedQueryNode extends Node {
    public function prep(SharedStore $shared): string { return $shared->question; }
    public function exec(mixed $q): array { return getEmbedding($q); }
    public function post(SharedStore $shared, mixed $p, mixed $e): ?string {
        $shared->queryEmbed = $e;
        return 'default';
    }
}

class RetrieveDocsNode extends Node {
    public function prep(SharedStore $shared): array {
        return [$shared->queryEmbed, $shared->index, $shared->allChunks];
    }
    public function exec(mixed $p): string {
        [$qEmb, $index, $chunks] = $p;
        $bestId = searchIndex($index, $qEmb, topK: 1)[0];
        return $chunks[$bestId];
    }
    public function post(SharedStore $shared, mixed $p, mixed $chunk): ?string {
        $shared->retrievedChunk = $chunk;
        return 'default';
    }
}

class GenerateAnswerNode extends Node {
    public function prep(SharedStore $shared): array {
        return [$shared->question, $shared->retrievedChunk];
    }
    public function exec(mixed $p): string {
        return callLlm("Question: {$p[0]}\nContext: {$p[1]}\nAnswer:");
    }
    public function post(SharedStore $shared, mixed $p, mixed $answer): ?string {
        $shared->answer = $answer;
        return null;
    }
}

$embedQ   = new EmbedQueryNode();
$retrieve = new RetrieveDocsNode();
$generate = new GenerateAnswerNode();
$embedQ->next($retrieve)->next($generate);

$onlineFlow = new Flow($embedQ);
$shared->question = 'Why do people like cats?';
$onlineFlow->run($shared);
echo $shared->answer;
```

---

## 4. Map-Reduce

Split large input into independent units (Map), process each (BatchNode), then combine into a final result (Reduce).

```php
use PocketFlow\BatchNode;
use PocketFlow\Node;
use PocketFlow\Flow;

class SummarizeAllFilesNode extends BatchNode {
    public function prep(SharedStore $shared): array {
        return array_map(fn($k) => [$k, $shared->files[$k]], array_keys($shared->files));
    }

    public function exec(mixed $item): array {
        [$filename, $content] = $item;
        $summary = callLlm("Summarize: {$content}");
        return [$filename, $summary];
    }

    public function post(SharedStore $shared, mixed $p, mixed $results): ?string {
        $shared->fileSummaries = [];
        foreach ($results as [$fn, $summary]) {
            $shared->fileSummaries[$fn] = $summary;
        }
        return 'default';
    }
}

class CombineSummariesNode extends Node {
    public function prep(SharedStore $shared): array { return $shared->fileSummaries; }
    public function exec(mixed $summaries): string {
        $text = '';
        foreach ($summaries as $fn => $s) {
            $text .= "{$fn}: {$s}\n---\n";
        }
        return callLlm("Combine into final summary:\n{$text}");
    }
    public function post(SharedStore $shared, mixed $p, mixed $final): ?string {
        $shared->finalSummary = $final;
        return null;
    }
}

$mapNode = new SummarizeAllFilesNode();
$reduceNode = new CombineSummariesNode();
$mapNode->next($reduceNode);

$flow = new Flow($mapNode);
```

**Performance tip:** For I/O-bound tasks (API calls, LLM), use `AsyncParallelBatchNode` to run the map phase concurrently.

---

## 5. Multi-Agent

Multiple agents running concurrently, communicating via message queues. Uses `AsyncFlow` + `React\Promise\all`.

```php
use PocketFlow\AsyncNode;
use PocketFlow\AsyncFlow;
use PocketFlow\SharedStore;
use React\Promise\PromiseInterface;
use React\Promise\Deferred;
use function React\Async\async;
use function React\Async\await;

// Simple async MessageQueue
class MessageQueue {
    private array $queue = [];
    private array $deferreds = [];
    public function put(mixed $msg): void {
        if (!empty($this->deferreds)) {
            $d = array_shift($this->deferreds);
            $d->resolve($msg);
            return;
        }
        $this->queue[] = $msg;
    }
    public function get(): PromiseInterface {
        if (!empty($this->queue)) {
            return \React\Promise\resolve(array_shift($this->queue));
        }
        $d = new Deferred();
        $this->deferreds[] = $d;
        return $d->promise();
    }
}

// Agent that processes messages
class AgentNode extends AsyncNode {
    public function prepAsync(SharedStore $shared): PromiseInterface {
        return $this->params['messageQueue']->get();
    }
    public function execAsync(mixed $msg): PromiseInterface {
        return async(function() use ($msg) {
            echo "Agent received: {$msg}\n";
            return $msg;
        })();
    }
    public function postAsync(SharedStore $shared, mixed $p, mixed $e): PromiseInterface {
        return async(fn() => 'continue')();
    }
}

// Two agents with separate queues
$shared = new SharedStore();
$agentQueue = new MessageQueue();
$shared->messages = $agentQueue;

$agentNode = new AgentNode();
$agentNode->on('continue')->next($agentNode);  // Self-loop

$flow = new AsyncFlow($agentNode);
$flow->setParams(['messageQueue' => $agentQueue]);

$agentQueue->put('Start!');

await($flow->runAsync($shared));
```

**Important:** Multi-Agent is advanced. Start with a single agent first. Only add multi-agent when you genuinely need concurrent, independent reasoning paths.

---

## 6. Structured Output

Prompt the LLM to return YAML (easier escaping than JSON), validate in `exec()`, and let the Node's retry mechanism handle validation failures.

```php
use PocketFlow\Node;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

class SummarizeStructuredNode extends Node {
    public function exec(mixed $text): array {
        $prompt = "Summarize as YAML with 3 bullets:\n```yaml\nsummary:\n  - bullet1\n  - bullet2\n  - bullet3\n```\n\nText: {$text}";
        $response = callLlm($prompt);

        preg_match('/```yaml\s*(.*?)\s*```/s', $response, $matches);
        $yaml = $matches[1] ?? $response;

        $parsed = Yaml::parse($yaml);
        if (!isset($parsed['summary']) || !is_array($parsed['summary'])) {
            throw new \RuntimeException('Missing "summary" key in LLM output');
        }
        return $parsed;
    }

    public function post(SharedStore $shared, mixed $p, mixed $result): ?string {
        $shared->summary = $result['summary'];
        return null;
    }
}

// Use maxRetries for validation failures
$node = new SummarizeStructuredNode(maxRetries: 3);
```

**Why YAML over JSON:** LLMs struggle with JSON escaping (quotes, newlines). YAML block literals (`|`) preserve multi-line text without escaping.
