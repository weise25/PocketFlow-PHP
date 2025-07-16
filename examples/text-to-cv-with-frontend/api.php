<?php
// api.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

set_time_limit(120);
session_start();

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/utils/llm_api.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

// This function sends a Server-Sent Event (SSE)
function send_sse($event, $data)
{
    echo "event: $event\n";
    echo "data: " . json_encode($data) . "\n\n";
    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();
}

$method = $_SERVER['REQUEST_METHOD'];

// POST requests are used to set up the state for an action
if ($method === 'POST') {
    header('Content-Type: application/json');
    $request_body = json_decode(file_get_contents('php://input'), true);
    $action = $request_body['action'] ?? null;

    if (!$action) {
        echo json_encode(['success' => false, 'message' => 'No action provided in POST']);
        exit();
    }

    switch ($action) {
        case 'start':
            $_SESSION['shared'] = new stdClass(); // Reset
            $_SESSION['shared']->prompt = $request_body['prompt'];
            $_SESSION['stream_action'] = 'stream_full_plan';
            break;
        case 'submit_edit':
            $_SESSION['shared']->edit_prompt = $request_body['edit_prompt'];
            $_SESSION['stream_action'] = 'stream_edit';
            break;
        case 'approve':
            $_SESSION['stream_action'] = 'generate_pdf';
            break;
        case 'restart':
            session_destroy();
            break;
    }

    echo json_encode(['success' => true, 'message' => 'State prepared for streaming.']);
    exit();
}

// GET requests are used by EventSource to initiate the streaming
if ($method === 'GET') {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');

    $stream_action = $_SESSION['stream_action'] ?? null;

    if (!$stream_action) {
        send_sse('error', ['message' => 'No streaming action pending.']);
        exit();
    }

    unset($_SESSION['stream_action']);

    try {
        $full_response = '';
        $stream_handler = function ($stream) use (&$full_response) {
            foreach ($stream as $response) {
                $chunk = $response->choices[0]->delta->content;
                if ($chunk !== null) {
                    $full_response .= $chunk;
                    send_sse('plan_chunk', ['chunk' => $chunk]);
                }
            }
        };

        switch ($stream_action) {
            case 'stream_full_plan':
                $prompt = $_SESSION['shared']->prompt;
                $system_prompt = <<<'PROMPT'
You are a helpful assistant that thinks step-by-step. Your task is to create a plan for a CV based on the user's request. Structure your response as a single YAML document with two top-level keys: `agent_plan` and `cv`.

**IMPORTANT RULES:**
1.  **agent_plan**: Use the ReAct framework (Observation, Thought, Action).
2.  **cv**: Create the detailed, structured data for the CV.
3.  **Quoting**: If any text value contains a colon (:), you **MUST** wrap that entire value in double quotes (").

Example:
```yaml
agent_plan:
  observation: "User wants a CV for a senior software engineer. Requirement: a modern, compact design."
  thought: I will structure the CV with a header, summary, experience, skills, and education. The key is to highlight achievements and use a clean layout.
  action: Generate the structured CV plan.
cv:
  header:
    name: John Doe
    # ... rest of the cv data ...
```
PROMPT;
                $history = [['role' => 'system', 'content' => $system_prompt]];
                $stream_handler(call_llm_stream($prompt, $history));
                break;

            case 'stream_edit':
                $prompt = $_SESSION['shared']->edit_prompt;
                $edit_system_prompt = "You are a helpful assistant. The user wants to edit a CV plan. Based on the user's edit prompt, you MUST re-generate and output the ENTIRE, complete, and valid YAML document with both the `agent_plan` and `cv` sections, incorporating the requested changes. Do not only output the changed parts or a confirmation message.";
                $history = [
                    ['role' => 'system', 'content' => $edit_system_prompt],
                    ['role' => 'user', 'content' => "Here is the current plan to be edited:\n\n" . Yaml::dump($_SESSION['shared']->cv_plan)]
                ];
                $stream_handler(call_llm_stream($prompt, $history));
                break;

            case 'generate_pdf':
                require_once __DIR__ . '/nodes.php';
                send_sse('thought_chunk', ['chunk' => 'Okay, the plan is approved. I will now generate the final HTML based on the provided structure.']);
                sleep(1);
                
                $generateHtmlNode = new GenerateHtmlNode();
                $cv_yaml = Yaml::dump($_SESSION['shared']->cv_plan['cv']);
                $_SESSION['shared']->cv_html = $generateHtmlNode->exec($cv_yaml);

                send_sse('thought_chunk', ['chunk' => '\nConverting the HTML to a PDF document...']);
                $convertToPdfNode = new ConvertToPdfNode();
                $_SESSION['shared']->pdf_path = $convertToPdfNode->exec($_SESSION['shared']->cv_html);
                sleep(1);

                send_sse('finished', ['pdf_url' => 'outputs/' . basename($_SESSION['shared']->pdf_path)]);
                session_destroy();
                exit();
        }

        // Universal YAML parsing logic for streamed responses
        // First, try to extract YAML from markdown code blocks
        if (preg_match('/```yaml\s*\n(.*?)\n```/s', $full_response, $matches)) {
            $yamlToParse = trim($matches[1]);
        } else {
            // Fallback: remove any markdown code block markers
            $yamlToParse = preg_replace('/^```yaml\s*|\s*```$/m', '', $full_response);
            $yamlToParse = trim($yamlToParse);
        }
        
        // Debug: Log the raw response for troubleshooting
        error_log("Raw LLM Response Length: " . strlen($full_response));
        error_log("YAML to Parse Length: " . strlen($yamlToParse));
        error_log("First 500 chars of YAML: " . substr($yamlToParse, 0, 500));
        
        try {
            $parsed_yaml = Yaml::parse($yamlToParse);
            error_log("YAML parsed successfully");
        } catch (ParseException $e) {
            error_log("YAML Parse Error: " . $e->getMessage() . " at line " . $e->getParsedLine());
            
            $lines = explode("\n", $yamlToParse);
            $errorLine = $e->getParsedLine();
            if ($errorLine > 0 && isset($lines[$errorLine - 1])) {
                $line_content = $lines[$errorLine - 1];
                error_log("Problematic line: " . $line_content);
                if (preg_match('/^(\s*[^:]+):\s+(.*)$/', $line_content, $matches)) {
                    $lines[$errorLine - 1] = $matches[1] . ': "' . trim($matches[2]) . '"';
                    $fixed_yaml = implode("\n", $lines);
                    $parsed_yaml = Yaml::parse($fixed_yaml); // Retry parsing
                    error_log("YAML fixed and parsed successfully");
                } else {
                    error_log("Could not fix YAML automatically");
                    throw $e; // Re-throw if our fix fails
                }
            } else {
                error_log("Error line not available for fixing");
                throw $e; // Re-throw if line is not available
            }
        }

        // Validate that we have the required structure
        if (!isset($parsed_yaml['agent_plan']) || !isset($parsed_yaml['cv'])) {
            error_log("Missing required YAML structure. Keys present: " . implode(', ', array_keys($parsed_yaml)));
            throw new Exception("Invalid YAML structure: missing 'agent_plan' or 'cv' sections");
        }

        $_SESSION['shared']->cv_plan = $parsed_yaml;
        error_log("Sending plan_finished event");
        send_sse('plan_finished', ['plan' => $parsed_yaml]);

    } catch (Exception $e) {
        send_sse('error', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    }
    exit();
}
