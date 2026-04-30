<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/flow.php';
require_once __DIR__ . '/utils/openrouter.php';
require_once __DIR__ . '/utils/brave_search.php';

use Dotenv\Dotenv;
use PocketFlow\SharedStore;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__);
$loadedVars = $dotenv->load();

// --- Initialize Shared State ---
$shared = new SharedStore();
$shared->env = $loadedVars;

// Make shared state globally accessible for utility functions
global $shared;

// --- Get User Input ---
echo "Please enter your research query: ";
$query = trim(fgets(STDIN));
$shared->query = $query;

// --- Create and Run the Flow ---
$agentFlow = createResearchAgentFlow();
$agentFlow->run($shared);

// --- Display the Final Result ---
echo "\n--- Final Result ---\n";
if (isset($shared->final_report)) {
    echo $shared->final_report;
} elseif (isset($shared->final_answer)) {
    echo $shared->final_answer;
} else {
    echo "The agent did not produce a final report or answer.\n";
}
echo "\n";
