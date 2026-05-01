<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/utils/callLlm.php';

use PocketFlow\Node;
use PocketFlow\SharedStore;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class GetSchema extends Node
{
    public function prep(SharedStore $shared): mixed
    {
        return $shared->dbPath;
    }

    public function exec(mixed $dbPath): mixed
    {
        $pdo = new PDO("sqlite:{$dbPath}");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")
            ->fetchAll(PDO::FETCH_COLUMN);

        $lines = [];
        foreach ($tables as $tableName) {
            $lines[] = "Table: {$tableName}";
            $columns = $pdo->query("PRAGMA table_info({$tableName})")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($columns as $col) {
                $lines[] = "  - {$col['name']} ({$col['type']})";
            }
            $lines[] = '';
        }

        return implode("\n", array_slice($lines, 0, -1));
    }

    public function post(SharedStore $shared, mixed $prepResult, mixed $execResult): ?string
    {
        $shared->schema = $execResult;

        if ($shared->verbose ?? true) {
            echo "\n===== DB SCHEMA =====\n\n";
            echo $execResult . "\n";
            echo "\n=====================\n";
        }

        return 'default';
    }
}

class GenerateSQL extends Node
{
    public function __construct()
    {
        parent::__construct(maxRetries: 2, wait: 1);
    }

    public function prep(SharedStore $shared): mixed
    {
        return [
            'query' => $shared->naturalQuery,
            'schema' => $shared->schema,
            'onTrace' => $shared->onTrace ?? null,
        ];
    }

    public function exec(mixed $prepResult): mixed
    {
        ['query' => $query, 'schema' => $schema, 'onTrace' => $onTrace] = $prepResult;

        $prompt = <<<PROMPT
Given the following SQLite database schema:
{$schema}

User's question: "{$query}"

First, provide a short, friendly response in plain text (1-2 sentences) explaining what SQL you'll write.
Then, provide ONLY the SQLite query in a YAML block under the key 'sql'.

Example:

I'll find the products by grouping them by category and counting.
```yaml
sql: |
  SELECT category, COUNT(*) AS total
  FROM products
  GROUP BY category
```
PROMPT;

        if ($onTrace !== null) {
            $onTrace('thinking_start', []);
        }

        $filter = createStreamFilter($onTrace);
        $fullResponse = callLlmStream($prompt, $filter);

        if ($onTrace !== null) {
            $onTrace('thinking_end', []);
        }

        return $this->parseResponse($fullResponse);
    }

    public function post(SharedStore $shared, mixed $prepResult, mixed $execResult): ?string
    {
        $shared->generatedSql = $execResult['sql'];
        $shared->debugAttempts = 0;

        if ($shared->verbose ?? true) {
            echo "\n===== GENERATED SQL =====\n\n";
            echo $execResult['sql'] . "\n";
            echo "\n=========================\n";
        }

        return 'default';
    }

    private function parseResponse(string $response): array
    {
        $yamlPos = strpos($response, '```yaml');
        if ($yamlPos !== false) {
            $message = trim(substr($response, 0, $yamlPos));
            $afterTag = substr($response, $yamlPos + 7);
            $closingPos = strpos($afterTag, '```');
            $yamlStr = $closingPos !== false ? trim(substr($afterTag, 0, $closingPos)) : trim($afterTag);
        } else {
            $fencePos = strpos($response, '```');
            if ($fencePos === false) {
                throw new \RuntimeException('LLM response missing code block');
            }
            $message = trim(substr($response, 0, $fencePos));
            $afterFence = substr($response, $fencePos + 3);
            $closingPos = strpos($afterFence, '```');
            $yamlStr = $closingPos !== false ? trim(substr($afterFence, 0, $closingPos)) : trim($afterFence);
        }

        try {
            $parsed = Yaml::parse($yamlStr);
        } catch (ParseException $e) {
            throw new \RuntimeException('Failed to parse YAML from LLM response: ' . $e->getMessage());
        }

        if (!isset($parsed['sql']) || !is_string($parsed['sql'])) {
            throw new \RuntimeException('LLM YAML response missing "sql" key');
        }

        return [
            'sql' => rtrim($parsed['sql'], ";\n\r\t "),
            'message' => $message ?: '',
        ];
    }
}

class ExecuteSQL extends Node
{
    public function prep(SharedStore $shared): mixed
    {
        $onTrace = $shared->onTrace ?? null;
        if ($onTrace !== null) {
            $sql = $shared->generatedSql ?? '';
            $truncated = truncateSqlTrace($sql);
            $onTrace('executing', ['sql' => $truncated, 'fullSql' => $sql]);
        }

        return [
            'dbPath' => $shared->dbPath,
            'sql' => $shared->generatedSql,
            'verbose' => $shared->verbose ?? true,
            'onTrace' => $onTrace,
        ];
    }

    public function exec(mixed $prepResult): mixed
    {
        ['dbPath' => $dbPath, 'sql' => $sql, 'verbose' => $verbose, 'onTrace' => $onTrace] = $prepResult;

        $pdo = new PDO("sqlite:{$dbPath}");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $startTime = microtime(true);

        try {
            $stmt = $pdo->query($sql);
        } catch (\PDOException $e) {
            $duration = round(microtime(true) - $startTime, 3);
            return [
                'success' => false,
                'result' => $e->getMessage(),
                'columns' => [],
                'duration' => $duration,
            ];
        }

        $duration = round(microtime(true) - $startTime, 3);

        if ($verbose) {
            echo "SQL executed in {$duration} seconds.\n";
        }

        $trimmedUpper = strtoupper(trim($sql));
        if (str_starts_with($trimmedUpper, 'SELECT') || str_starts_with($trimmedUpper, 'WITH')) {
            $columns = [];
            if ($stmt !== false) {
                for ($i = 0; $i < $stmt->columnCount(); $i++) {
                    $meta = $stmt->getColumnMeta($i);
                    $columns[] = $meta['name'] ?? "column_{$i}";
                }
                $results = $stmt->fetchAll(PDO::FETCH_NUM);
            }
            return [
                'success' => true,
                'result' => $results,
                'columns' => $columns,
                'duration' => $duration,
            ];
        }

        $rowCount = $stmt !== false ? $stmt->rowCount() : 0;
        return [
            'success' => true,
            'result' => "Query OK. Rows affected: {$rowCount}",
            'columns' => [],
            'duration' => $duration,
        ];
    }

    public function post(SharedStore $shared, mixed $prepResult, mixed $execResult): ?string
    {
        $success = $execResult['success'];
        $resultOrError = $execResult['result'];
        $columns = $execResult['columns'];
        $duration = $execResult['duration'];
        $verbose = $shared->verbose ?? true;
        $onTrace = $shared->onTrace ?? null;

        if ($success) {
            $shared->finalResult = $resultOrError;
            $shared->resultColumns = $columns;

            if ($onTrace !== null) {
                $onTrace('executed', ['duration' => $duration]);
            }

            if ($verbose) {
                echo "\n===== SQL EXECUTION SUCCESS =====\n\n";
                if (is_array($resultOrError)) {
                    if ($columns) {
                        echo implode(' | ', $columns) . "\n";
                        echo str_repeat('-', array_sum(array_map('strlen', $columns)) + 3 * (count($columns) - 1)) . "\n";
                    }
                    if (empty($resultOrError)) {
                        echo "(No results found)\n";
                    } else {
                        foreach ($resultOrError as $row) {
                            echo implode(' | ', array_map('strval', $row)) . "\n";
                        }
                    }
                } else {
                    echo $resultOrError . "\n";
                }
                echo "\n================================\n";
            }
            return null;
        }

        $shared->executionError = $resultOrError;
        $shared->debugAttempts = ($shared->debugAttempts ?? 0) + 1;
        $maxAttempts = $shared->maxDebugAttempts ?? 3;

        if ($onTrace !== null) {
            $onTrace('error', ['error' => $resultOrError, 'attempt' => $shared->debugAttempts, 'max' => $maxAttempts]);
        }

        if ($verbose) {
            echo "\n===== SQL EXECUTION FAILED (Attempt {$shared->debugAttempts}) =====\n\n";
            echo "Error: {$shared->executionError}\n";
            echo "\n=========================================\n";
        }

        if ($shared->debugAttempts >= $maxAttempts) {
            if ($onTrace !== null) {
                $onTrace('giving_up', ['attempts' => $maxAttempts, 'error' => $resultOrError]);
            }
            if ($verbose) {
                echo "Max debug attempts ({$maxAttempts}) reached. Stopping.\n";
            }
            $shared->finalError = "Failed to execute SQL after {$maxAttempts} attempts. "
                . "Last error: {$shared->executionError}";
            return null;
        }

        if ($verbose) {
            echo "Attempting to debug the SQL...\n";
        }
        return 'error_retry';
    }
}

class DebugSQL extends Node
{
    public function __construct()
    {
        parent::__construct(maxRetries: 2, wait: 1);
    }

    public function prep(SharedStore $shared): mixed
    {
        $onTrace = $shared->onTrace ?? null;
        $attempt = ($shared->debugAttempts ?? 0) + 1;
        $maxAttempts = $shared->maxDebugAttempts ?? 3;

        if ($onTrace !== null) {
            $onTrace('debugging', [
                'attempt' => $attempt,
                'max' => $maxAttempts,
                'error' => $shared->executionError ?? '',
            ]);
        }

        return [
            'query' => $shared->naturalQuery ?? '',
            'schema' => $shared->schema ?? '',
            'failedSql' => $shared->generatedSql ?? '',
            'error' => $shared->executionError ?? '',
            'onTrace' => $onTrace,
        ];
    }

    public function exec(mixed $prepResult): mixed
    {
        [
            'query' => $query,
            'schema' => $schema,
            'failedSql' => $failedSql,
            'error' => $errorMessage,
            'onTrace' => $onTrace
        ] = $prepResult;

        $prompt = <<<PROMPT
The SQL query below was generated for the question "{$query}" but it failed:
```sql
{$failedSql}
```

Error message: {$errorMessage}

Database schema:
{$schema}

First, acknowledge the error in one sentence and explain your fix.
Then, provide the corrected SQLite query in a YAML block under the key 'sql'.

Example:

The query failed because the column name was misspelled. Let me use the correct name.
```yaml
sql: |
  SELECT correct_column FROM table_name
```
PROMPT;

        if ($onTrace !== null) {
            $onTrace('thinking_start', []);
        }

        $filter = createStreamFilter($onTrace);
        $fullResponse = callLlmStream($prompt, $filter);

        if ($onTrace !== null) {
            $onTrace('thinking_end', []);
        }

        return $this->parseResponse($fullResponse);
    }

    public function post(SharedStore $shared, mixed $prepResult, mixed $execResult): ?string
    {
        $shared->generatedSql = $execResult['sql'];
        unset($shared->executionError);

        if ($shared->verbose ?? true) {
            echo "\n===== REVISED SQL =====\n\n";
            echo $execResult['sql'] . "\n";
            echo "\n=======================\n";
        }

        return 'default';
    }

    private function parseResponse(string $response): array
    {
        $yamlPos = strpos($response, '```yaml');
        if ($yamlPos !== false) {
            $message = trim(substr($response, 0, $yamlPos));
            $afterTag = substr($response, $yamlPos + 7);
            $closingPos = strpos($afterTag, '```');
            $yamlStr = $closingPos !== false ? trim(substr($afterTag, 0, $closingPos)) : trim($afterTag);
        } else {
            $fencePos = strpos($response, '```');
            if ($fencePos === false) {
                throw new \RuntimeException('LLM response missing code block');
            }
            $message = trim(substr($response, 0, $fencePos));
            $afterFence = substr($response, $fencePos + 3);
            $closingPos = strpos($afterFence, '```');
            $yamlStr = $closingPos !== false ? trim(substr($afterFence, 0, $closingPos)) : trim($afterFence);
        }

        try {
            $parsed = Yaml::parse($yamlStr);
        } catch (ParseException $e) {
            throw new \RuntimeException('Failed to parse YAML from LLM response: ' . $e->getMessage());
        }

        if (!isset($parsed['sql']) || !is_string($parsed['sql'])) {
            throw new \RuntimeException('LLM YAML response missing "sql" key');
        }

        return [
            'sql' => rtrim($parsed['sql'], ";\n\r\t "),
            'message' => $message ?: '',
        ];
    }
}

function truncateSqlTrace(string $sql): string
{
    $lines = explode("\n", trim($sql));
    if (count($lines) <= 3) {
        return trim($sql);
    }
    return implode("\n", array_slice($lines, 0, 3)) . "\n...";
}

function createStreamFilter(?callable $onTrace): callable
{
    $fenceFound = false;
    $pending = '';

    return function (string $chunk) use ($onTrace, &$fenceFound, &$pending): void {
        if ($onTrace === null || $fenceFound) {
            return;
        }

        $pending .= $chunk;
        $delimPos = strpos($pending, '```');

        if ($delimPos === false) {
            if (strlen($pending) > 3) {
                $safe = substr($pending, 0, -3);
                $onTrace('thinking_chunk', ['text' => $safe]);
                $pending = substr($pending, -3);
            }
        } else {
            $messagePart = substr($pending, 0, $delimPos);
            if ($messagePart !== '') {
                $onTrace('thinking_chunk', ['text' => $messagePart]);
            }
            $fenceFound = true;
        }
    };
}
