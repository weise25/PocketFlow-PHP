<?php

declare(strict_types=1);

function callLlm(string $prompt): string
{
    $baseUrl = $_ENV['LLM_BASE_URL']
        ?? throw new \RuntimeException('LLM_BASE_URL not set in .env');
    $modelId = $_ENV['LLM_MODEL_ID']
        ?? throw new \RuntimeException('LLM_MODEL_ID not set in .env');
    $apiKey = $_ENV['LLM_API_KEY']
        ?? throw new \RuntimeException('LLM_API_KEY not set in .env');

    $endpoint = rtrim($baseUrl, '/') . '/chat/completions';

    $payload = json_encode([
        'model' => $modelId,
        'messages' => [
            ['role' => 'user', 'content' => $prompt],
        ],
    ], JSON_THROW_ON_ERROR);

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError !== '') {
        throw new \RuntimeException('LLM API request failed: ' . $curlError);
    }

    /** @var array{choices?: array{0: array{message?: array{content?: string}}}} $data */
    $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

    if ($httpCode !== 200) {
        $errorMsg = $data['error']['message'] ?? ($data['error']['code'] ?? 'Unknown error');
        throw new \RuntimeException("LLM API returned HTTP {$httpCode}: {$errorMsg}");
    }

    if (!isset($data['choices'][0]['message']['content'])) {
        throw new \RuntimeException('LLM API response missing choices[0].message.content');
    }

    return $data['choices'][0]['message']['content'];
}

function callLlmStream(string $prompt, ?callable $onChunk = null): string
{
    $baseUrl = $_ENV['LLM_BASE_URL']
        ?? throw new \RuntimeException('LLM_BASE_URL not set in .env');
    $modelId = $_ENV['LLM_MODEL_ID']
        ?? throw new \RuntimeException('LLM_MODEL_ID not set in .env');
    $apiKey = $_ENV['LLM_API_KEY']
        ?? throw new \RuntimeException('LLM_API_KEY not set in .env');

    $endpoint = rtrim($baseUrl, '/') . '/chat/completions';

    $payload = json_encode([
        'model' => $modelId,
        'messages' => [
            ['role' => 'user', 'content' => $prompt],
        ],
        'stream' => true,
    ], JSON_THROW_ON_ERROR);

    $contentBuffer = '';
    $lineBuffer = '';

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_WRITEFUNCTION  => function ($ch, string $rawData) use ($onChunk, &$contentBuffer, &$lineBuffer): int {
            $lineBuffer .= $rawData;
            $lines = explode("\n", $lineBuffer);
            $lineBuffer = array_pop($lines);

            foreach ($lines as $line) {
                $line = trim($line);
                if (!str_starts_with($line, 'data: ')) {
                    continue;
                }

                $jsonStr = substr($line, 6);
                if ($jsonStr === '[DONE]') {
                    continue;
                }

                try {
                    $chunk = json_decode($jsonStr, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    continue;
                }

                $content = $chunk['choices'][0]['delta']['content'] ?? '';
                if ($content !== '') {
                    $contentBuffer .= $content;
                    if ($onChunk !== null) {
                        $onChunk($content);
                    }
                }
            }

            return strlen($rawData);
        },
    ]);

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($result === false && $curlError !== '' && !str_contains($curlError, 'transfer closed')) {
        throw new \RuntimeException('LLM API request failed: ' . $curlError);
    }

    if ($httpCode !== 200) {
        $errorData = json_decode($contentBuffer, true);
        $errorMsg = is_array($errorData) ? ($errorData['error']['message'] ?? 'Unknown error') : 'Unknown error';
        throw new \RuntimeException("LLM API returned HTTP {$httpCode}: {$errorMsg}");
    }

    return $contentBuffer;
}
