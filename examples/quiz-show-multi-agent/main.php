<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/flow.php';

// Load the environment variableas from .env
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Interactive query of win Points
$pointsToWin = 0;
while ($pointsToWin < 1 || $pointsToWin > 10) {
    $input = readline("How many points to win the game? (1-10): ");
    if (is_numeric($input) && $input >= 1 && $input <= 10) {
        $pointsToWin = (int)$input;
    } else {
        echo "Invalid input. Please enter a number between 1 and 10.\n";
    }
}

// Start the quizshow with the specified number of points
create_quiz_show_flow($pointsToWin);