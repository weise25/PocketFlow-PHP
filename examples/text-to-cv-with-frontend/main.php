<?php
// main.php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/flow.php';

use PocketFlow\SharedStore;

function main(): void {
    echo "Welcome to the CV Crafter Agent!\n";

    // Initialize the shared state object
    $shared = new SharedStore();
    $shared->initial_prompt = null;
    $shared->cv_plan = null;
    $shared->edit_history = [];
    $shared->cv_html = null;
    $shared->pdf_path = null;

    // Create the flow and run it
    $cvFlow = createCvCrafterFlow();
    $cvFlow->run($shared);

    echo "\nProcess finished. Thank you for using the CV Crafter!\n";
}

main();
