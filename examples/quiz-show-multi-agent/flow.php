<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/nodes.php';
require_once __DIR__ . '/utils/openrouter_api.php';

use PocketFlow\SharedStore;
use PocketFlow\AsyncFlow;
use PocketFlow\AsyncNode;
use React\Promise\PromiseInterface;
use function React\Async\async;
use function React\Async\await;

function createQuizShowFlow(int $pointsToWin): void
{
    async(function () use ($pointsToWin) {
        // 1. Initialize the shared state
        $shared = new SharedStore();
        $shared->quizmasterQueue = new MessageQueue();
        $shared->player1Queue = new MessageQueue();
        $shared->player2Queue = new MessageQueue();
        $shared->player1_score = 0;
        $shared->player2_score = 0;
        $shared->question_count = 0;
        $shared->game_over = false;

        // 2. Define the models for the agents
        $models = [
            'quizmaster' => 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning:free',
            'player1' => 'poolside/laguna-xs.2:free',
            'player2' => 'deepseek/deepseek-v4-flash',
        ];

        // 3. Create the agent nodes
        $quizmasterNode = new QuizmasterAgent();
        $player1Node = new PlayerAgent();
        $player2Node = new PlayerAgent();

        // 4. Define the flow paths for each agent
        // The 'continue' path makes the agent loop.
        $quizmasterNode->on('continue')->next($quizmasterNode);
        $player1Node->on('continue')->next($player1Node);
        $player2Node->on('continue')->next($player2Node);

        // Explicitly define that 'end_game' terminates the flow without a warning.
        // next(null) means: "For this action, there is no successor. End the flow here."
        $quizmasterNode->on('end_game')->next(null);
        $player1Node->on('end_game')->next(null);
        $player2Node->on('end_game')->next(null);

        // 5. Create the flows
        $quizmasterFlow = new AsyncFlow($quizmasterNode);
        $player1Flow = new AsyncFlow($player1Node);
        $player2Flow = new AsyncFlow($player2Node);

        // 6. Set the parameters for each flow
        $baseParams = [
            'points_to_win' => $pointsToWin,
            'shared_state' => $shared,
        ];
        $quizmasterFlow->setParams(array_merge($baseParams, [
            'model' => $models['quizmaster'],
            'player1_model' => $models['player1'],
            'player2_model' => $models['player2'],
        ]));
        $player1Flow->setParams(array_merge($baseParams, [
            'model' => $models['player1'],
            'name' => 'Player 1',
            'my_queue' => $shared->player1Queue,
            'personality' => 'Confident and a bit of a show-off'
        ]));
        $player2Flow->setParams(array_merge($baseParams, [
            'model' => $models['player2'],
            'name' => 'Player 2',
            'my_queue' => $shared->player2Queue,
            'personality' => 'Humble, thoughtful, and slightly nervous'
        ]));

        // 7. Start the game
        echo "--- Starting AI Quiz Show! First to {$pointsToWin} points wins! ---\n";
        $shared->quizmasterQueue->put(['type' => 'START_GAME']);

        // 8. Run all flows concurrently
        await(\React\Promise\all([
            $quizmasterFlow->runAsync($shared),
            $player1Flow->runAsync($shared),
            $player2Flow->runAsync($shared),
        ]));

        echo "\n--- Quiz Show has finished. ---\n";
    })();
}
