# Agentic Coding: The Official Guide for Building with PocketFlow-PHP

> **Attention AI Agent:** This is your primary instruction manual. Follow these rules and best practices strictly to generate correct, robust, and bug-free PHP code for the PocketFlow framework. Failure to adhere to these rules will result in errors.

## The Golden Rules

1.  **THE CORE FRAMEWORK IS READ-ONLY.** The file `src/PocketFlow.php` is the engine. You are **strictly forbidden** from ever editing or suggesting edits to this file. The `src/` directory is a **read-only** zone.

2.  **USE THE THREE MAIN FILES.** All your application code **must** be written in these three files in the project root:
    -   `nodes.php`: For all `Node`, `BatchNode`, and `AsyncNode` class definitions.
    -   `flow.php`: For functions that create and wire up `Flows`.
    -   `main.php`: The single entry point that calls a function from `flow.php`.

3.  **UTILITIES GO IN `utils/`.** Any function that communicates with the outside world (e.g., calling an LLM API, a database, or a web search) **must** be placed in its own, separate file inside the `utils/` directory (e.g., `utils/llm_api.php`, `utils/database_connector.php`).

4.  **STATE MANAGEMENT IS CRITICAL.**
    -   **DO** use the `$shared` object (`stdClass`) to manage all mutable application state (e.g., results, counters, lists of items).
    -   **DO NOT** use `static` properties or variables inside Node classes to manage state. This will fail in loops.
    -   **DO NOT** use `$this->params` to store mutable state. It is for immutable configuration only.

5.  **STRICT TYPE COMPATIBILITY.**
    -   The `post()` method of a `Node` **must** have the return type `?string`. If it does not decide the next action, it **must** end with `return null;`.
    -   All `_async` methods (`prep_async`, `exec_async`, `post_async`) **must** have the return type `React\Promise\PromiseInterface`.
    -   To return a promise, **always** use the pattern `return async(function() { ... })();`. Do not forget the final `()`.

6.  **ALWAYS IMPORT CLASSES WITH `use`.**
    -   Any file that references a class (e.g., `AsyncNode`, `Flow`, `PromiseInterface`) **must** include a `use` statement for that class at the top of the file.
    -   This is especially important in `flow.php` and `nodes.php`, even for classes from the core framework or vendor packages.

7.  **DO NOT CALL `startNode()`.**
    -   The `Flow` class **does not have** a public `startNode()` method.
    -   **Correct Pattern:** To reference the start node (e.g., for a loop), store it in a variable *before* creating the flow.
    -   **CORRECT:** `$myNode->on('continue')->next($myNode); $flow = new Flow($myNode);`

8.  **RESPECT CLOSURE SCOPE.**
    -   To use a property of `$this` (like `$this->params`) inside a closure, read it into a local variable *before* the closure, then pass that variable into the closure with `use()`.
    -   **CORRECT:**
        ```php
        public function my_method(): PromiseInterface {
            $key_from_params = $this->params['key']; // Read value outside
            return async(function() use ($key_from_params) { // Pass value in
                $value = $key_from_params; // Use the local variable
            })();
        }
        ```

---

## Best Practices & Recommendations

Follow these practical guidelines to ensure your application is robust and well-structured.

### 1. Dependency Management

-   **DO NOT** edit `composer.json` manually.
-   **ALWAYS** add new dependencies using the command line:
    ```bash
    composer require vendor/package
    ```
-   This ensures you get the latest compatible versions. Common packages you might need are:
    -   `openai-php/client` (for LLM APIs)
    -   `guzzlehttp/guzzle` (HTTP client, often required by other packages)
    -   `vlucas/phpdotenv` (for managing environment variables)

### 2. Environment Variables (.env)

-   All API keys and secrets **must** be stored in a `.env` file in the project root.
-   Load the `.env` file **at the very beginning** of your `main.php` script.
-   **ALWAYS** access environment variables via the `$_ENV` superglobal, as it is more reliable than `getenv()`.

**Correct Pattern in `main.php`:**
```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
// ... other requires

// Load environment variables FIRST.
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Now you can run your application.
main();
```

### 3. Example Utility: OpenAI-Compatible API Client

This is a robust, reusable template for calling any OpenAI-compatible API. Use this as a **reference** for creating your own utility files.

**File: `utils/llm_api.php`**
```php
<?php
use OpenAI\Client;
use React\Promise\PromiseInterface;
use function React\Async\async;

/**
 * Calls any OpenAI-compatible API asynchronously.
 *
 * @param string $model The model identifier.
 * @param array $messages The chat messages.
 * @param string $apiKey The API key.
 * @param string $baseUri The base URI of the API endpoint.
 * @param ?callable $streamCallback Optional callback for streaming responses.
 * @return PromiseInterface A promise that resolves with the full response text.
 */
function call_llm_api_async(
    string $model,
    array $messages,
    string $apiKey,
    string $baseUri,
    ?callable $streamCallback = null
): PromiseInterface {
    return async(function () use ($model, $messages, $apiKey, $baseUri, $streamCallback) {
        try {
            $client = OpenAI::factory()
                ->withApiKey($apiKey)
                ->withBaseUri($baseUri)
                ->withHttpHeader('HTTP-Referer', 'http://localhost') // Required for some providers
                ->withHttpHeader('X-Title', 'PocketFlow-PHP') // Recommended for some providers
                ->make();

            $fullResponse = '';
            if (is_callable($streamCallback)) {
                $stream = $client->chat()->createStreamed(['model' => $model, 'messages' => $messages]);
                foreach ($stream as $response) {
                    $chunk = $response->choices[0]->delta->content;
                    if ($chunk !== null) {
                        $fullResponse .= $chunk;
                        $streamCallback($chunk);
                    }
                }
            } else {
                $response = $client->chat()->create(['model' => $model, 'messages' => $messages]);
                $fullResponse = $response->choices[0]->message->content;
            }
            return $fullResponse;

        } catch (Exception $e) {
            return "API Error: " . $e->getMessage();
        }
    })();
}
```

### 4. Structured Output (JSON/YAML)

-   When you need an LLM to return structured data, instruct it to respond with a JSON object inside a code block.
-   **Always** extract the JSON from the response robustly before decoding.

**Correct Pattern in a Node's `exec` or `exec_async` method:**
```php
$llmResponse = await(call_llm_api_async(...));

$jsonString = '';
// This regex reliably extracts a JSON object from the LLM's text.
if (preg_match('/\{.*?\}/s', $llmResponse, $matches)) {
    $jsonString = $matches[0];
}

$data = json_decode($jsonString, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    // Handle the case where the LLM failed to produce valid JSON.
    return ['error' => 'Invalid JSON response from LLM.'];
}

// Now you can safely use $data
return $data;
```

---

## The Development Workflow

Follow these steps in order. Refer to the `README.md` for correct code examples.

1.  **Human: Define Requirements.**
2.  **Human & AI: Design the Flow.**
    -   Draw a Mermaid diagram.
    -   Identify the nodes and the actions that connect them.
3.  **AI: Implement Utilities.**
    -   Create a new file in `utils/` for each external service.
    -   **Add all necessary `use` statements at the top.**
4.  **AI: Design the Data Schema.**
    -   Define the structure of the `$shared` object in `main.php`.
5.  **AI: Implement Nodes in `nodes.php`.**
    -   For each node, implement `prep()`, `exec()`, and `post()`.
    -   Pay close attention to the return types and scope rules.
6.  **AI: Implement the Flow in `flow.php`.**
    -   Create a function (e.g., `create_my_flow()`) that instantiates nodes and connects them.
7.  **AI: Implement the Entry Point in `main.php`.**
    -   `main.php` should be very simple: require files, create `$shared`, call the flow function, and run the flow.
8.  **Human & AI: Test and Optimize.**
    -   Run `php main.php`.
    -   Debug any errors by checking adherence to the Golden Rules.

---

## Example Project Structure (with Generic Code)

This is the mandatory structure. The code provided is a generic, simple "Question & Answer" example that serves as a **starting template**.

```
my_project/
├── main.php
├── nodes.php
├── flow.php
├── utils/
├── composer.json
└── src/
    └── PocketFlow.php  <-- READ-ONLY
```

-   **`utils/`**: This directory holds all utility functions. Each utility that connects to a different external service **must** be in its own file. For example: `llm_api.php`, `database.php`, `web_search.php`.

-   **`nodes.php`**: Contains all the node class definitions.
    ```php
    <?php
    // nodes.php
    require_once __DIR__ . '/vendor/autoload.php';
    // require_once __DIR__ . '/utils/llm_api.php'; // Uncomment if you use it

    use PocketFlow\Node;
    use stdClass;

    class GetInputNode extends Node
    {
        public function exec(mixed $p): mixed
        {
            return readline("Enter your input: ");
        }
        
        public function post(stdClass $shared, mixed $p, mixed $execResult): ?string
        {
            $shared->input = $execResult;
            return 'default';
        }
    }

    class ProcessNode extends Node
    {
        public function prep(stdClass $shared): mixed
        {
            return $shared->input;
        }
        
        public function exec(mixed $input): mixed
        {
            // In a real app, you would call a utility here, e.g., an LLM.
            return "Processed output for input: '{$input}'";
        }
        
        public function post(stdClass $shared, mixed $p, mixed $execResult): ?string
        {
            $shared->output = $execResult;
            return null;
        }
    }
    ```

-   **`flow.php`**: Implements functions that create and wire up flows.
    ```php
    <?php
    // flow.php
    require_once __DIR__ . '/nodes.php';
    use PocketFlow\Flow;

    function create_simple_flow(): Flow
    {
        // Create nodes
        $inputNode = new GetInputNode();
        $processNode = new ProcessNode();
        
        // Connect nodes in sequence
        $inputNode->next($processNode);
        
        // Create flow starting with the input node
        return new Flow($inputNode);
    }
    ```

-   **`main.php`**: Serves as the project's entry point.
    ```php
    <?php
    // main.php
    require_once __DIR__ . '/vendor/autoload.php';
    require_once __DIR__ . '/flow.php';

    // In a real app, you would load .env here.
    // $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    // $dotenv->load();

    function main(): void
    {
        $shared = new stdClass();
        $shared->input = null;
        $shared->output = null;

        // Create the flow and run it
        $simpleFlow = create_simple_flow();
        $simpleFlow->run($shared);
        
        echo "Input: {$shared->input}\n";
        echo "Output: {$shared->output}\n";
    }

    main();
    ```

By following these precise instructions, you will minimize errors and build reliable applications with PocketFlow-PHP.