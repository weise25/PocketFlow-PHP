<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

function call_llm(string $prompt, string $model = "deepseek/deepseek-chat-v3-0324:free"): string
{
    global $shared; // Access the global shared state
    $apiKey = $shared->env['OPENROUTER_API_KEY'] ?? null;

    if (!$apiKey) {
        return 'Error: OPENROUTER_API_KEY not found in environment.';
    }

    $client = new Client();

    try {
        $response = $client->post('https://openrouter.ai/api/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
                'HTTP-Referer' => 'http://localhost', // Required by OpenRouter
                'X-Title' => 'PocketFlow-PHP',
            ],
            'json' => [
                'model' => $model,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt]
                ],
            ],
        ]);

        $body = json_decode((string) $response->getBody(), true);
        return $body['choices'][0]['message']['content'] ?? 'Error: Could not extract content from LLM response.';
    } catch (GuzzleException $e) {
        error_log("LLM API Error: " . $e->getMessage());
        return "Error communicating with the LLM.";
    }
}
