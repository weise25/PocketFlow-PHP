<?php
// utils/llm_api.php

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

// Load .env file
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

function call_llm(string $prompt, array $history = []): string
{
    $apiKey = $_ENV['OPENROUTER_API_KEY'];
    $llmName = $_ENV['LLM_NAME'];

    if (!$apiKey) {
        throw new Exception("OPENROUTER_API_KEY is not set in .env file.");
    }

    $client = OpenAI::factory()
        ->withApiKey($apiKey)
        ->withBaseUri('https://openrouter.ai/api/v1')
        ->withHttpHeader('HTTP-Referer', 'http://localhost')
        ->make();

    $messages = $history;
    $messages[] = ['role' => 'user', 'content' => $prompt];

    $response = $client->chat()->create([
        'model' => $llmName,
        'messages' => $messages,
    ]);

    return $response->choices[0]->message->content;
}

function call_llm_stream(string $prompt, array $history = []): iterable
{
    $apiKey = $_ENV['OPENROUTER_API_KEY'];
    $llmName = $_ENV['LLM_NAME'];

    if (!$apiKey) {
        throw new Exception("OPENROUTER_API_KEY is not set in .env file.");
    }

    $client = OpenAI::factory()
        ->withApiKey($apiKey)
        ->withBaseUri('https://openrouter.ai/api/v1')
        ->withHttpHeader('HTTP-Referer', 'http://localhost')
        ->make();

    $messages = $history;
    $messages[] = ['role' => 'user', 'content' => $prompt];

    return $client->chat()->createStreamed([
        'model' => $llmName,
        'messages' => $messages,
    ]);
}

// To test this utility directly from the command line:
if (basename(__FILE__) === basename($_SERVER['PHP_SELF'])) {
    try {
        echo "Testing LLM API...\n";
        $response = call_llm("What is the capital of France?");
        echo "LLM Response: " . $response . "\n";
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
