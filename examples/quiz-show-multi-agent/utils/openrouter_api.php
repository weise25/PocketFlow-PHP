<?php

use OpenAI\Client;
use React\Promise\PromiseInterface;
use function React\Async\async;

/**
 * Calls the OpenRouter API asynchronously.
 *
 * @param string $model The model to use.
 * @param array $messages The message history.
 * @param ?callable $streamCallback An optional callback that is called for each stream chunk.
 * @return PromiseInterface A promise that resolves to the full response of the LLM.
 */
function call_openrouter_async(string $model, array $messages, ?callable $streamCallback = null): PromiseInterface
{
    return async(function () use ($model, $messages, $streamCallback) {
        try {
            $apiKey = $_ENV['OPENROUTER_API_KEY'] ?? null;
            if (empty($apiKey)) throw new Exception("OpenRouter API Key not found in .env file.");

            $client = OpenAI::factory()
                ->withApiKey($apiKey)
                ->withBaseUri('https://openrouter.ai/api/v1')
                ->withHttpHeader('HTTP-Referer', 'http://localhost') // Required for OpenRouter
                ->withHttpHeader('X-Title', 'PocketFlow-PHP Quiz Show') // Recommended for OpenRouter
                ->make();

            $fullResponse = '';
            // If a callback is provided, we enable streaming.
            if (is_callable($streamCallback)) {
                $stream = $client->chat()->createStreamed([
                    'model' => $model,
                    'messages' => $messages,
                ]);

                foreach ($stream as $response) {
                    $chunk = $response->choices[0]->delta->content;
                    if ($chunk !== null) {
                        $fullResponse .= $chunk;
                        // Call the callback with the new chunk.
                        $streamCallback($chunk);
                    }
                }
            } else {
                $response = $client->chat()->create([
                    'model' => $model,
                    'messages' => $messages,
                ]);
                $fullResponse = $response->choices[0]->message->content;
            }
            return $fullResponse;

        } catch (Exception $e) {
            echo "API Error: " . $e->getMessage() . "\n";
            return "I am having trouble connecting to my brain right now.";
        }
    })();
}
