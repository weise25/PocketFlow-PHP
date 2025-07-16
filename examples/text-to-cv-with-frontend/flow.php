<?php
// flow.php
require_once __DIR__ . '/nodes.php';

use PocketFlow\Flow;

function create_cv_crafter_flow(): Flow {
    // 1. Create all the nodes
    $getInitialPrompt = new GetInitialPromptNode();
    $createPlan = new CreatePlanNode(maxRetries: 3, wait: 5);
    $reviewPlan = new ReviewPlanNode();
    $editPlan = new EditPlanNode(maxRetries: 3, wait: 5);
    $generateHtml = new GenerateHtmlNode(maxRetries: 3, wait: 10);
    $convertToPdf = new ConvertToPdfNode();
    $stopNode = new StopNode(); // The dummy node

    // 2. Connect the nodes to define the flow logic
    $getInitialPrompt->next($createPlan);
    $createPlan->next($reviewPlan);

    // Branching based on user feedback
    $reviewPlan->on("approved")->next($generateHtml);
    $reviewPlan->on("needs_edit")->next($editPlan);
    $reviewPlan->next($stopNode); // Explicitly define the default path to stop

    // Loop back after editing
    $editPlan->next($reviewPlan);

    // Final sequence
    $generateHtml->next($convertToPdf);

    // 3. Create the flow starting with the first node
    return new Flow($getInitialPrompt);
}
