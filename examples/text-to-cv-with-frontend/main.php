<?php
// main.php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/flow.php';

function main(): void {
    echo "Welcome to the CV Crafter Agent!\n";

    // Initialize the shared state object
    $shared = new stdClass();
    $shared->initial_prompt = null;
    $shared->cv_plan = null;
    $shared->edit_history = [];
    $shared->cv_html = null;
    $shared->pdf_path = null;

    // Create the flow and run it
    $cvFlow = create_cv_crafter_flow();
    $cvFlow->run($shared);

    echo "\nProcess finished. Thank you for using the CV Crafter!\n";
}

main();
