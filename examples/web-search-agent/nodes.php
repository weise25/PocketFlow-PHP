<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/utils/openrouter.php';
require_once __DIR__ . '/utils/brave_search.php';

use PocketFlow\Node;
use PocketFlow\BatchNode;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

class DecideActionNode extends Node
{
    public function exec(mixed $prepResult): array
    {
        $history = empty($prepResult['search_history']) ? "No research has been done yet." : implode("\n", $prepResult['search_history']);
        $pending_searches = empty($prepResult['search_plan']) ? "No searches are planned." : implode("\n", $prepResult['search_plan']);

        $prompt = <<<PROMPT
        You are a research agent. Your task is to answer the user's query by reasoning about the available information and choosing the correct tool.

        **Observation:**
        Here is the current state of your work:
        -   User Query: "{$prepResult['query']}"
        -   Search History: {$history}
        -   Pending Search Plan: {$pending_searches}

        **Thought:**
        Based on the Observation, you must now decide on the next action. Follow these steps:
        1.  Analyze the User Query. Does it require external, up-to-date information?
        2.  Review the Search History. Have you already gathered enough information?
        3.  Check the Pending Search Plan. Are there searches you still need to run?
        4.  Based on this analysis, formulate a thought process that leads to your final action.

        **Action:**
        Choose ONE of the following actions and provide your response in the specified YAML format.

        -   `plan_searches`: If the query is complex and you have no search plan.
        -   `execute_searches`: If you have a pending search plan.
        -   `synthesize_report`: If your search history contains enough information to answer the query.
        -   `answer_simple`: If the query is simple and can be answered from your own knowledge without searching.

        ```yaml
        thought: "Your step-by-step reasoning here, explaining your choice based on the observation."
        action: "the_single_action_to_take"
        ```
        PROMPT;

        $response = call_llm($prompt);
        if (str_starts_with($response, 'Error:')) {
            return ['action' => 'error', 'message' => $response];
        }

        preg_match('/```yaml\s*(.*?)\s*```/s', $response, $matches);
        $yamlString = $matches[1] ?? '';

        try {
            $parsed = Yaml::parse($yamlString);
            if (!is_array($parsed) || !isset($parsed['action'])) {
                return ['action' => 'error', 'message' => 'LLM returned malformed YAML (missing action key).'];
            }
            return $parsed;
        } catch (ParseException $e) {
            return ['action' => 'error', 'message' => 'YAML Parse Error: ' . $e->getMessage()];
        }
    }

    public function prep(stdClass $shared): array
    {
        return [
            'query' => $shared->query,
            'search_history' => $shared->search_history ?? [],
            'search_plan' => $shared->search_plan ?? [],
        ];
    }

    public function post(stdClass $shared, mixed $p, mixed $decision): ?string
    {
        if (empty($decision['action'])) {
            $shared->error_message = 'The agent failed to decide on a valid action.';
            return 'error';
        }

        if ($decision['action'] === 'error') {
            $shared->error_message = $decision['message'] ?? 'An unknown error occurred in the decision node.';
        }
        
        echo "Decision: {$decision['action']}\n";
        return $decision['action'];
    }
}

class PlanSearchesNode extends Node
{
    public function exec(mixed $query): array
    {
        $prompt = <<<PROMPT
        You are a strategic research planner. Your task is to create a list of specific, targeted search queries that will effectively answer the user's main query.
        
        **Main Query:** "{$query}"

        **Instructions:**
        1.  **Deconstruct the Query:** Break down the user's query into its core components.
        2.  **Think Step-by-Step:** What information needs to be found first? What follows from that?
        3.  **Be Specific:** Avoid generic searches. Instead of "current president", try "official list of US presidents" and "who won the 2024 US presidential election".

        Provide your answer as a YAML list of search queries.

        ```yaml
        search_queries:
          - "specific search term 1"
          - "targeted search term 2"
          - "..."
        ```
        PROMPT;

        $response = call_llm($prompt);
        preg_match('/```yaml\s*(.*?)\s*```/s', $response, $matches);
        $yamlString = $matches[1] ?? '';

        try {
            $parsed = Yaml::parse($yamlString);
            $searchQueries = $parsed['search_queries'] ?? [];
        } catch (ParseException $e) {
            $searchQueries = [$query];
        }

        if (empty($searchQueries)) {
            $searchQueries[] = $query;
        }

        return $searchQueries;
    }

    public function prep(stdClass $shared): mixed
    {
        return $shared->query;
    }

    public function post(stdClass $shared, mixed $p, mixed $search_plan): ?string
    {
        $shared->search_plan = $search_plan;
        echo "Search plan created.\n";
        return 'continue';
    }
}

class ExecuteAllSearchesNode extends BatchNode
{
    public function prep(stdClass $shared): array
    {
        return $shared->search_plan;
    }

    public function exec(mixed $search_term): string
    {
        usleep(500000); // Proactive 0.5-second delay to avoid rate limits
        echo "Searching for: {$search_term}\n";
        return call_brave_search($search_term);
    }

    public function post(stdClass $shared, mixed $p, mixed $searchResultList): ?string
    {
        $shared->search_history = $searchResultList;
        $shared->search_plan = []; // Clear the plan
        echo "All searches complete.\n";
        return 'continue';
    }
}

class SynthesizeReportNode extends Node
{
    public function exec(mixed $prepResult): string
    {
        $historyString = trim(implode("\n---\n", $prepResult['history']));

        if (empty($historyString) || str_contains($historyString, "No search results found.")) {
            return "I could not find any information to answer the query after searching the web. Please try a different query.";
        }

        $prompt = <<<PROMPT
        You are a critical research analyst. Your task is to synthesize a final report based *only* on the provided research material. Adhere strictly to the user's original request.

        **Original User Query:** "{$prepResult['query']}"

        **Collected Research:**
        ---
        {$historyString}
        ---

        **CRITICAL INSTRUCTIONS:**
        1.  **STICK TO THE FACTS:** Do NOT use any information from outside the "Collected Research" section. Do not use your own general knowledge.
        2.  **CITE YOUR SOURCES:** For every piece of information, you must mention the source URL it came from, like `[Source: https://example.com]`. If the research is empty, state that you have no information.
        3.  **OBEY THE QUERY:** Pay close attention to all constraints in the original query, such as the desired language (e.g., "in german language").
        4.  **STRUCTURE:** Format the output as a clean, well-structured markdown report.

        Generate the final report now.
        PROMPT;

        return call_llm($prompt);
    }

    public function prep(stdClass $shared): mixed
    {
        return [
            'query' => $shared->query,
            'history' => $shared->search_history,
        ];
    }

    public function post(stdClass $shared, mixed $p, mixed $report): ?string
    {
        $shared->final_report = $report;
        echo "Report synthesized.\n";
        return null;
    }
}

class AnswerSimpleNode extends Node
{
    public function exec(mixed $query): string
    {
        $prompt = "Answer the following question directly: {$query}";
        return call_llm($prompt);
    }

    public function prep(stdClass $shared): mixed
    {
        return $shared->query;
    }

    public function post(stdClass $shared, mixed $p, mixed $answer): ?string
    {
        $shared->final_answer = $answer;
        echo "Simple answer provided.\n";
        return null;
    }
}

class ErrorNode extends Node
{
    public function exec(mixed $p): mixed
    {
        return null;
    }

    public function post(stdClass $shared, mixed $p, mixed $e): ?string
    {
        echo "\n--- An Error Occurred ---\n";
        echo $shared->error_message . "\n";
        echo "Please check your .env file and API keys.\n";
        return null;
    }
}