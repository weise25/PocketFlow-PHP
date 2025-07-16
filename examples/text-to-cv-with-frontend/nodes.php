<?php
// nodes.php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/utils/llm_api.php';
require_once __DIR__ . '/utils/pdf_converter.php';

use PocketFlow\Node;

// This node is now controlled by the API, it just retrieves the prompt from shared state
class GetInitialPromptNode extends Node {
    public function prep(stdClass $shared): mixed {
        return $shared->initial_prompt ?? null;
    }

    public function exec(mixed $prompt): ?string {
        if (empty($prompt)) {
            // This will stop the flow if no prompt is provided, preventing errors.
            return null;
        }
        return $prompt;
    }

    public function post(stdClass $shared, mixed $p, mixed $execResult): ?string {
        if ($execResult === null) {
            return 'stop'; // Stop if exec returned null
        }
        $shared->initial_prompt = $execResult;
        return "default";
    }
}

class CreatePlanNode extends Node {
    public function prep(stdClass $shared): mixed {
        return $shared->initial_prompt;
    }

    public function exec(mixed $prompt): string {
        $system_prompt = "You are a helpful assistant. Based on the user's request, create a structured plan for a CV. The plan should be in YAML format. For example:\nsections:\n  - personal_details:\n      name: John Doe\n      email: john.doe@example.com\n  - summary: A brief professional summary.\n  - experience:\n      - position: Senior Developer\n        company: Tech Inc.\n        years: 2020-2024\n        description: Description of responsibilities.\n  - education:\n      - degree: BSc Computer Science\n        university: University of Example\n        years: 2016-2020\n";
        return call_llm($prompt, [['role' => 'system', 'content' => $system_prompt]]);
    }

    public function post(stdClass $shared, mixed $p, mixed $execResult): ?string {
        $shared->cv_plan = $execResult;
        return "default";
    }
}

// This node now reads the decision from the shared state, set by the API.
class ReviewPlanNode extends Node {
    public function prep(stdClass $shared): mixed {
        return $shared->user_decision ?? null;
    }

    public function exec(mixed $decision): ?string {
        if ($decision === 'approve') {
            return "approved";
        }
        if ($decision === 'edit') {
            return "needs_edit";
        }
        // If no decision is set, we stop here and wait for the user.
        return null;
    }

    public function post(stdClass $shared, mixed $p, mixed $execResult): ?string {
        return $execResult;
    }
}

// This node gets the edit prompt from the shared state.
class EditPlanNode extends Node {
    public function prep(stdClass $shared): mixed {
        return [
            'plan' => $shared->cv_plan ?? null,
            'edit_prompt' => $shared->edit_prompt ?? null
        ];
    }

    public function exec(mixed $p): ?string {
        if (empty($p['plan']) || empty($p['edit_prompt'])) {
            return null; // Not enough info to edit
        }

        $system_prompt = "You are a helpful assistant. The user wants to edit a CV plan. Based on the user's edit prompt, update the following YAML plan. Output only the updated YAML plan.";
        
        return call_llm($p['edit_prompt'], [
            ['role' => 'system', 'content' => $system_prompt],
            ['role' => 'user', 'content' => "Here is the current plan to be edited:\n" . $p['plan']]
        ]);
    }

    public function post(stdClass $shared, mixed $p, mixed $execResult): ?string {
        if ($execResult === null) {
            return 'stop';
        }
        $shared->cv_plan = $execResult;
        if (!isset($shared->edit_history)) {
            $shared->edit_history = [];
        }
        $shared->edit_history[] = $p['edit_prompt'];
        return "default";
    }
}

class GenerateHtmlNode extends Node {
    public function prep(stdClass $shared): mixed {
        return $shared->cv_plan;
    }

    public function exec(mixed $p): string {
        $prompt = "Based on the following YAML plan, generate a complete, modern, and well-styled HTML document for a CV. Use inline CSS for styling. The HTML should be self-contained and ready to be converted to a PDF. Crucially, the entire CV must fit on a single A4 page. Use compact styling, smaller font sizes (e.g., 10pt-11pt), and potentially a two-column layout to ensure all content is visible on one page without scrolling.\n\n" . $p;
        return call_llm($prompt);
    }

    public function post(stdClass $shared, mixed $p, mixed $execResult): ?string {
        preg_match('/<html.*<\/html>/s', $execResult, $matches);
        $shared->cv_html = $matches[0] ?? $execResult;
        return "default";
    }
}

class ConvertToPdfNode extends Node {
    public function prep(stdClass $shared): mixed {
        return $shared->cv_html;
    }

    public function exec(mixed $p): string {
        error_log("Agent Step: Converting HTML to PDF...");
        $filename = 'cv_' . time() . '.pdf';
        return convert_html_to_pdf($p, $filename);
    }

    public function post(stdClass $shared, mixed $p, mixed $execResult): ?string {
        $shared->pdf_path = $execResult;
        return null; // End of the flow
    }
}

// A dummy node that does nothing, used to explicitly stop a flow path.
class StopNode extends Node {
    public function exec(mixed $p): mixed {
        // This node does nothing. It's a designated end point.
        return null;
    }
}