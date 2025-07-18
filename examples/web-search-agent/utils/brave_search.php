<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\ClientException;

function call_brave_search(string $query): string
{
    global $shared;
    $apiKey = $shared->env['BRAVE_API_KEY'] ?? null;

    if (!$apiKey) {
        return 'Error: BRAVE_API_KEY not found in environment.';
    }

    $client = new Client();
    $maxRetries = 3;
    $retryDelay = 2; // seconds

    for ($attempt = 1; $attempt <= $maxRetries; ++$attempt) {
        try {
            $response = $client->get('https://api.search.brave.com/res/v1/web/search', [
                'headers' => [
                    'Accept' => 'application/json',
                    'X-Subscription-Token' => $apiKey,
                ],
                'query' => [
                    'q' => $query,
                ],
            ]);

            $body = json_decode((string) $response->getBody(), true);

            $results = [];
            if (!empty($body['web']['results'])) {
                foreach ($body['web']['results'] as $res) {
                    $results[] = "[Title: {$res['title']}] [URL: {$res['url']}] [Snippet: {$res['description']}]";
                }
            }
            return empty($results) ? "No search results found for query: {$query}" : implode("\n", $results);

        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() === 429) {
                if ($attempt < $maxRetries) {
                    echo "Rate limit hit. Retrying in {$retryDelay} seconds...\n";
                    sleep($retryDelay);
                    $retryDelay *= 2; // Exponential backoff
                } else {
                    return "Error: Brave Search API rate limit exceeded after multiple retries.";
                }
            } else {
                return "Brave Search API Error: " . $e->getMessage();
            }
        } catch (GuzzleException $e) {
            return "Brave Search API Error: " . $e->getMessage();
        }
    }

    return "Error: Brave Search API request failed after all retries.";
}