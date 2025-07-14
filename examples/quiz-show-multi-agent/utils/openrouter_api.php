<?php

use OpenAI\Client;
use React\Promise\PromiseInterface;
use function React\Async\async;

/**
 * Ruft die OpenRouter API asynchron auf.
 *
 * @param string $model Das zu verwendende Modell.
 * @param array $messages Die Nachrichten-Historie.
 * @param ?callable $streamCallback Ein optionaler Callback, der für jeden Stream-Chunk aufgerufen wird.
 * @return PromiseInterface Ein Promise, das zur vollständigen Antwort des LLM auflöst.
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
                ->withHttpHeader('HTTP-Referer', 'http://localhost') // Erforderlich für OpenRouter
                ->withHttpHeader('X-Title', 'PocketFlow-PHP Quiz Show') // Empfohlen für OpenRouter
                ->make();

            $fullResponse = '';
            // Wenn ein Callback übergeben wird, aktivieren wir Streaming.
            if (is_callable($streamCallback)) {
                $stream = $client->chat()->createStreamed([
                    'model' => $model,
                    'messages' => $messages,
                ]);

                foreach ($stream as $response) {
                    $chunk = $response->choices[0]->delta->content;
                    if ($chunk !== null) {
                        $fullResponse .= $chunk;
                        // Rufe den Callback mit dem neuen Chunk auf.
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