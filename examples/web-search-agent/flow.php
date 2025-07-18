<?php
declare(strict_types=1);

require_once __DIR__ . '/nodes.php';
use PocketFlow\Flow;

function create_research_agent_flow(): Flow
{
    $decideNode = new DecideActionNode();
    $planNode = new PlanSearchesNode();
    $searchNode = new ExecuteAllSearchesNode();
    $reportNode = new SynthesizeReportNode();
    $answerNode = new AnswerSimpleNode();
    $errorNode = new ErrorNode();

    // Define the agent's decision branches and the main loop
    $decideNode->on('plan_searches')->next($planNode);
    $decideNode->on('execute_searches')->next($searchNode);
    $decideNode->on('synthesize_report')->next($reportNode);
    $decideNode->on('answer_simple')->next($answerNode);
    $decideNode->on('error')->next($errorNode);

    // Loop back to the decision node after planning or searching
    $planNode->on('continue')->next($decideNode);
    $searchNode->on('continue')->next($decideNode);

    return new Flow($decideNode);
}