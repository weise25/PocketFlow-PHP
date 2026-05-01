<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/flow.php';
require_once __DIR__ . '/utils/populateDb.php';

use Dotenv\Dotenv;
use PocketFlow\SharedStore;

const C_RESET  = "\033[0m";
const C_BOLD   = "\033[1m";
const C_DIM    = "\033[2m";
const C_CYAN   = "\033[36m";
const C_GREEN  = "\033[32m";
const C_RED    = "\033[31m";
const C_YELLOW = "\033[33m";
const C_WHITE  = "\033[37m";

if (!file_exists(__DIR__ . '/.env')) {
    fwrite(STDERR, "Error: .env file not found. Copy .env.example to .env and fill in your API key.\n");
    exit(1);
}

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();
$dotenv->required(['LLM_BASE_URL', 'LLM_MODEL_ID', 'LLM_API_KEY']);

define('DB_FILE', __DIR__ . '/ecommerce.db');

populateDatabase(DB_FILE);

$shared = new SharedStore();
$shared->dbPath = DB_FILE;
$shared->verbose = true;

if (isset($argv[1])) {
    $query = implode(' ', array_slice($argv, 1));
    $shared->naturalQuery = $query;
    $shared->maxDebugAttempts = 3;
    $shared->debugAttempts = 0;
    $shared->finalResult = null;
    $shared->finalError = null;

    createFullFlow()->run($shared);
    oneShotResult($shared);
    exit;
}

repl($shared);

function repl(SharedStore $shared): void
{
    ob_implicit_flush(true);
    $shared->verbose = false;

    showHeader();

    $maxRetries = 3;
    $schemaLoaded = false;

    readline_completion_function(static function (string $input): array {
        $commands = ['/help', '/schema', '/retries', '/exit'];
        if ($input === '' || str_starts_with($input, '/')) {
            return array_filter($commands, fn(string $c) => str_starts_with($c, $input));
        }
        return [];
    });

    $shared->onTrace = function (string $type, array $data): void {
        match ($type) {
            'thinking_start' => traceThinkingStart(),
            'thinking_chunk' => traceThinkingChunk($data['text']),
            'thinking_end' => traceThinkingEnd(),
            'executing' => traceExecuting($data['sql']),
            'executed' => traceExecuted($data['duration']),
            'error' => traceError($data['error'], $data['attempt'], $data['max']),
            'debugging' => traceDebugging($data['attempt'], $data['max'], $data['error']),
            'giving_up' => traceGivingUp($data['attempts'], $data['error']),
            default => null,
        };
    };

    while (true) {

        $input = readline(C_GREEN . 'text2sql> ' . C_RESET);

        if ($input === false || $input === null) {
            echo "\n";
            break;
        }

        $input = trim($input);
        if ($input === '') {
            continue;
        }

        readline_add_history($input);

        if (str_starts_with($input, '/')) {
            if (handleCommand($input, $maxRetries, $shared)) {
                break;
            }
            continue;
        }

        if (!$schemaLoaded) {
            echo C_DIM . "  🔍 Reading database schema..." . C_RESET;
            $shared->verbose = false;

            try {
                $getSchema = new GetSchema();
                $prepRes = $getSchema->prep($shared);
                $execRes = $getSchema->exec($prepRes);
                $getSchema->post($shared, $prepRes, $execRes);
            } catch (\Throwable $e) {
                echo C_RED . " Error: {$e->getMessage()}" . C_RESET . "\n";
                continue;
            }
            echo C_DIM . " done" . C_RESET . "\n";
            $schemaLoaded = true;
        }

        resetQueryState($shared, $input, $maxRetries);

        try {
            createQueryFlow()->run($shared);
        } catch (\Throwable $e) {
            traceException($e->getMessage());
            continue;
        }

        echo "\n";
        showResult($shared);
    }

    echo C_DIM . "\nGoodbye!" . C_RESET . "\n";
}

function showHeader(): void
{
    echo C_CYAN . C_BOLD . "\n╔══════════════════════════════════════════════╗\n";
    echo "║" . C_RESET . C_BOLD . "  Text-to-SQL REPL" . C_RESET . C_CYAN . C_BOLD . "                              ║\n";
    echo "║" . C_RESET . C_DIM . "  Ecommerce database — ask questions in plain  " . C_CYAN . C_BOLD . "║\n";
    echo "║" . C_RESET . C_DIM . "  English and get real SQL results.            " . C_CYAN . C_BOLD . "║\n";
    echo "╠══════════════════════════════════════════════╣\n";
    echo "║" . C_RESET . C_DIM . "  /help      Show this help                   " . C_CYAN . C_BOLD . "║\n";
    echo "║" . C_RESET . C_DIM . "  /schema    Show database schema             " . C_CYAN . C_BOLD . "║\n";
    echo "║" . C_RESET . C_DIM . "  /retries N Set max debug retries (default 3) " . C_CYAN . C_BOLD . "║\n";
    echo "║" . C_RESET . C_DIM . "  /exit      Quit                             " . C_CYAN . C_BOLD . "║\n";
    echo "╚══════════════════════════════════════════════╝" . C_RESET . "\n";
}

function handleCommand(string $input, int &$maxRetries, SharedStore $shared): bool
{
    $parts = explode(' ', $input, 2);
    $cmd = $parts[0];

    return match ($cmd) {
        '/help' => showHelp(),
        '/schema' => showSchema($shared),
        '/retries' => setRetries($parts, $maxRetries),
        '/exit' => true,
        default => unknownCommand($cmd),
    };
}

function showHelp(): false
{
    echo "\n" . C_BOLD . "Commands:" . C_RESET . "\n";
    echo "  " . C_CYAN . "/help" . C_RESET . "        Show this help\n";
    echo "  " . C_CYAN . "/schema" . C_RESET . "      Show database schema\n";
    echo "  " . C_CYAN . "/retries N" . C_RESET . "   Set max debug retries (e.g. /retries 5)\n";
    echo "  " . C_CYAN . "/exit" . C_RESET . "        Quit\n";
    echo "\n" . C_DIM . "Just type a question to run the text-to-SQL pipeline." . C_RESET . "\n\n";
    return false;
}

function showSchema(SharedStore $shared): false
{
    echo C_CYAN . "\n┌─ Database Schema " . str_repeat('─', 57) . "\n" . C_RESET;
    echo C_DIM . ($shared->schema ?? 'Schema not loaded yet. Run a query first.') . C_RESET . "\n";
    echo C_CYAN . "└" . str_repeat('─', 76) . "\n" . C_RESET . "\n";
    return false;
}

function setRetries(array $parts, int &$maxRetries): false
{
    if (isset($parts[1]) && is_numeric($parts[1]) && (int)$parts[1] >= 0) {
        $maxRetries = (int)$parts[1];
        echo C_GREEN . "Max debug retries set to {$maxRetries}." . C_RESET . "\n";
    } else {
        echo C_YELLOW . "Usage: /retries <number>" . C_RESET . "\n";
    }
    return false;
}

function unknownCommand(string $cmd): false
{
    echo C_YELLOW . "Unknown command '{$cmd}'. Type /help for available commands." . C_RESET . "\n";
    return false;
}

function resetQueryState(SharedStore $shared, string $query, int $maxRetries): void
{
    $shared->naturalQuery = $query;
    $shared->maxDebugAttempts = $maxRetries;
    $shared->debugAttempts = 0;
    $shared->finalResult = null;
    $shared->finalError = null;
    unset($shared->executionError);
    unset($shared->resultColumns);
}

function traceThinkingStart(): void
{
    echo "  " . C_DIM . "✨ ";
}

function traceThinkingChunk(string $text): void
{
    echo C_DIM . str_replace("\n", "\n     ", $text) . C_RESET;
    flush();
}

function traceThinkingEnd(): void
{
    echo C_RESET . "\n";
}

function traceExecuting(string $sql): void
{
    echo C_DIM . "  ⚡ ";
    echo C_BOLD . "Executing query..." . C_RESET;
    echo C_DIM . "\n     " . str_replace("\n", "\n     ", $sql) . C_RESET . "\n";
}

function traceExecuted(float $duration): void
{
    echo C_DIM . "  ✓  Query completed (" . number_format($duration, 3) . "s)" . C_RESET . "\n";
}

function traceError(string $error, int $attempt, int $max): void
{
    $shortError = strlen($error) > 80 ? substr($error, 0, 77) . '...' : $error;
    echo C_RED . "  ✗  Error: {$shortError}" . C_RESET . "\n";
}

function traceDebugging(int $attempt, int $max, string $error): void
{
    $shortError = strlen($error) > 60 ? substr($error, 0, 57) . '...' : $error;
    echo C_YELLOW . "  🔧 Fixing query ({$attempt}/{$max}) — {$shortError}" . C_RESET . "\n";
}

function traceGivingUp(int $attempts, string $error): void
{
    $shortError = strlen($error) > 60 ? substr($error, 0, 57) . '...' : $error;
    echo C_RED . "  ⛔ Giving up after {$attempts} attempts — {$shortError}" . C_RESET . "\n";
}

function traceException(string $message): void
{
    echo C_RED . "  ✗  Fatal: {$message}" . C_RESET . "\n";
}

function showResult(SharedStore $shared): void
{
    if (isset($shared->finalError)) {
        echo C_RED . "┌─ Error " . str_repeat('─', 67) . "\n" . C_RESET;
        echo C_RED . "│ " . wordwrap($shared->finalError, 74, "\n│ ") . C_RESET . "\n";
        echo C_RED . "└" . str_repeat('─', 76) . C_RESET . "\n\n";
        return;
    }

    $result = $shared->finalResult ?? null;

    if ($result === null) {
        echo C_YELLOW . "(No result)" . C_RESET . "\n\n";
        return;
    }

    if (is_string($result)) {
        echo C_GREEN . "┌─ Result " . str_repeat('─', 66) . "\n" . C_RESET;
        echo C_GREEN . "│ " . $result . C_RESET . "\n";
        echo C_GREEN . "└" . str_repeat('─', 76) . C_RESET . "\n\n";
        return;
    }

    if (is_array($result)) {
        $columns = $shared->resultColumns ?? [];
        echo C_GREEN . "┌─ Results " . str_repeat('─', 64) . "\n" . C_RESET;

        if ($columns && !empty($result)) {
            $widths = [];
            foreach ($columns as $i => $col) {
                $widths[$i] = strlen($col);
            }
            foreach ($result as $row) {
                foreach ($row as $i => $val) {
                    $widths[$i] = max($widths[$i], strlen((string)$val));
                }
            }

            $header = '│ ';
            foreach ($columns as $i => $col) {
                $header .= C_BOLD . str_pad($col, $widths[$i]) . C_RESET . ' │ ';
            }
            echo $header . "\n";

            $sep = '├';
            foreach ($widths as $w) {
                $sep .= str_repeat('─', $w + 2) . '┼';
            }
            echo rtrim($sep, '┼') . '┤' . "\n";

            foreach ($result as $row) {
                $line = '│ ';
                foreach ($row as $i => $val) {
                    $line .= C_DIM . str_pad((string)$val, $widths[$i]) . C_RESET . ' │ ';
                }
                echo $line . "\n";
            }
        } elseif (empty($result)) {
            echo C_DIM . "│ (No rows returned)" . C_RESET . "\n";
        } else {
            foreach ($result as $row) {
                echo C_DIM . '│ ' . implode(' | ', array_map('strval', $row)) . C_RESET . "\n";
            }
        }

        echo C_GREEN . "└" . str_repeat('─', 76) . C_RESET . "\n\n";
    }
}

function oneShotResult(SharedStore $shared): void
{
    if (isset($shared->finalError)) {
        echo "\n" . C_RED . "=== Workflow Completed with Error ===" . C_RESET . "\n";
        echo "Error: {$shared->finalError}\n";
    } elseif (isset($shared->finalResult)) {
        echo "\n" . C_GREEN . "=== Workflow Completed Successfully ===" . C_RESET . "\n";
    } else {
        echo "\n" . C_YELLOW . "=== Workflow Completed (Unknown State) ===" . C_RESET . "\n";
    }
    echo str_repeat('=', 36) . "\n";
}
