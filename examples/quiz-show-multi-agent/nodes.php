<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/utils/openrouter_api.php';

use PocketFlow\AsyncNode;
use React\Promise\PromiseInterface;
use React\Promise\Deferred;
use function React\Async\async;
use function React\Async\await;

/**
 * A simple async message queue for inter-agent communication.
 */
class MessageQueue {
    private array $queue = [];
    private array $deferreds = [];
    public function put(mixed $message): void { if (!empty($this->deferreds)) { $deferred = array_shift($this->deferreds); $deferred->resolve($message); return; } $this->queue[] = $message; }
    public function get(): PromiseInterface { if (!empty($this->queue)) { return \React\Promise\resolve(array_shift($this->queue)); } $deferred = new Deferred(); $this->deferreds[] = $deferred; return $deferred->promise(); }
}

/**
 * The Quizmaster Agent: Asks questions, evaluates answers, and hosts the show.
 */
class QuizmasterAgent extends AsyncNode
{
    public function prep_async(stdClass $shared): PromiseInterface {
        return $shared->quizmasterQueue->get();
    }

    public function exec_async(mixed $message): PromiseInterface {
        return async(function() use ($message) {
            $model = $this->params['model'];
            $shared = $this->params['shared_state'];
            $response = new stdClass();
            $response->action = 'ERROR';

            if ($shared->game_over || !isset($message['type']) || $message['type'] === 'GAME_OVER') {
                $response->action = 'END_GAME';
                return $response;
            }

            switch ($message['type']) {
                case 'START_GAME':
                    $prompt = "You are a charismatic quiz show host. Welcome the two AI contestants, {$this->params['player1_model']} and {$this->params['player2_model']}, to your show. Keep it exciting and brief.";
                    echo "Quizmaster: ";
                    await(call_openrouter_async($model, [['role' => 'user', 'content' => $prompt]], fn($chunk) => print($chunk)));
                    echo "\n";
                    $response->action = 'ASK_QUESTION';
                    break;

                case 'ASK_QUESTION':
                    $shared->question_count++;
                    echo "\n--- Round " . $shared->question_count . " (Score: P1 {$shared->player1_score} - P2 {$shared->player2_score}) ---\n";
                    $prompt = "You are a quizmaster. Ask one challenging but fun trivia question. Just the question, no intro.";
                    echo "Quizmaster: ";
                    $question = await(call_openrouter_async($model, [['role' => 'user', 'content' => $prompt]], fn($chunk) => print($chunk)));
                    echo "\n";
                    
                    $shared->current_question = $question;
                    $response->action = 'BROADCAST_AND_COLLECT';
                    $response->question = $question;
                    break;

                case 'ANSWERS_RECEIVED':
                    $p1_name = "Player 1";
                    $p2_name = "Player 2";
                    $prompt = "You are the judge of a quiz show. The question was: '{$shared->current_question}'.
                    - {$p1_name}'s answer: '{$message['answers']['Player 1']}'
                    - {$p2_name}'s answer: '{$message['answers']['Player 2']}'

                    Your task is to respond with a JSON object using the following structure, and nothing else.
                    {
                      \"commentary\": \"Your entertaining and brief commentary on the answers.\",
                      \"round_winner\": \"[Player 1|Player 2|Neither]\"
                    }
                    
                    Decision criteria: If both are correct, award the point to the one who was more concise or witty. If it's a true tie, choose 'Neither'.";
                    
                    $llmResponse = await(call_openrouter_async($model, [['role' => 'user', 'content' => $prompt]]));
                    
                    $jsonString = '';
                    if (preg_match('/\{.*?\}/s', $llmResponse, $matches)) {
                        $jsonString = $matches[0];
                    }
                    
                    $evaluation = json_decode($jsonString, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        echo "Quizmaster (confused): My evaluation card seems to be malfunctioning! We'll call it a draw.\n";
                        $evaluation = ['commentary' => 'A technical difficulty!', 'round_winner' => 'Neither'];
                    }

                    echo "Quizmaster: " . ($evaluation['commentary'] ?? 'No commentary.') . "\n";
                    
                    $winner = $evaluation['round_winner'] ?? 'Neither';
                    echo "JUDGEMENT: The point for this round goes to... {$winner}!\n";

                    if ($winner === 'Player 1') $shared->player1_score++;
                    if ($winner === 'Player 2') $shared->player2_score++;
                    
                    echo "CURRENT SCORE: [Player 1: {$shared->player1_score}] | [Player 2: {$shared->player2_score}]\n";

                    if ($shared->player1_score >= $this->params['points_to_win'] || $shared->player2_score >= $this->params['points_to_win']) {
                        $response->action = 'END_GAME';
                    } else {
                        $response->action = 'ASK_QUESTION';
                    }
                    break;
            }
            return $response;
        })();
    }

    public function post_async(stdClass $shared, mixed $p, mixed $execResult): PromiseInterface {
        return async(function() use ($shared, $execResult) {
            if (!is_object($execResult) || !isset($execResult->action)) return 'end_game';

            if ($execResult->action === 'END_GAME') {
                if (!$shared->game_over) {
                    $p1_name = "Player 1 ({$this->params['player1_model']})";
                    $p2_name = "Player 2 ({$this->params['player2_model']})";
                    $winner = $shared->player1_score > $shared->player2_score ? $p1_name : $p2_name;
                    if ($shared->player1_score === $shared->player2_score) $winner = "Both contestants in a stunning tie";
                    
                    echo "\nQuizmaster: And we have a winner! With a final score of {$shared->player1_score} to {$shared->player2_score}, congratulations to {$winner}! That's all the time we have for today. Thanks for watching!\n";
                    $shared->game_over = true;
                    $shared->player1Queue->put(['type' => 'GAME_OVER']);
                    $shared->player2Queue->put(['type' => 'GAME_OVER']);
                }
                return 'end_game';
            }

            switch ($execResult->action) {
                case 'BROADCAST_AND_COLLECT':
                    $shared->player1Queue->put(['type' => 'QUESTION', 'content' => $execResult->question]);
                    $shared->player2Queue->put(['type' => 'QUESTION', 'content' => $execResult->question]);

                    $answers = [];
                    while(count($answers) < 2 && !$shared->game_over) {
                        $msg = await($shared->quizmasterQueue->get());
                        if ($shared->game_over) break;
                        if ($msg['type'] === 'PLAYER_ANSWER') {
                            $answers[$msg['player']] = $msg['answer'];
                        }
                    }
                    if (!$shared->game_over) {
                        $shared->quizmasterQueue->put(['type' => 'ANSWERS_RECEIVED', 'answers' => $answers]);
                    }
                    break;

                case 'ASK_QUESTION':
                    $shared->quizmasterQueue->put(['type' => 'ASK_QUESTION']);
                    break;
            }
            return 'continue';
        })();
    }
}

/**
 * A generic player agent that answers questions.
 */
class PlayerAgent extends AsyncNode
{
    public function prep_async(stdClass $shared): PromiseInterface {
        return $this->params['my_queue']->get();
    }

    public function exec_async(mixed $message): PromiseInterface {
        return async(function() use ($message) {
            if ($message['type'] === 'GAME_OVER') return null;

            $model = $this->params['model'];
            $playerName = $this->params['name'];
            $personality = $this->params['personality'];

            echo "{$playerName} ({$model}): ";
            $prompt = "You are an AI contestant in a quiz show. Your personality is '{$personality}'. The quizmaster asked: '{$message['content']}'. Answer the question concisely, but let your personality shine through.";
            
            $answer = await(call_openrouter_async($model, [['role' => 'user', 'content' => $prompt]], fn($chunk) => print($chunk)));
            echo "\n";
            return $answer;
        })();
    }

    public function post_async(stdClass $shared, mixed $p, mixed $answer): PromiseInterface {
        return async(function() use ($shared, $answer) {
            if ($answer === null) return 'end_game';
            
            $shared->quizmasterQueue->put(['type' => 'PLAYER_ANSWER', 'player' => $this->params['name'], 'answer' => $answer]);
            return 'continue';
        })();
    }
}