# Example: Multi-Agent Quiz Show

This directory contains a **standalone, fully functional application** built with **PocketFlow-PHP**. It simulates a "Who Wants to be a Millionaire?" style quiz show featuring three distinct AI agents running concurrently.

This project is self-contained and does not depend on the parent directory. You can copy this folder anywhere on your system, run `composer install`, and it will work.

## Overview

-   **Quizmaster:** An AI agent that generates and asks trivia questions, moderates the game, and evaluates the players' answers.
-   **Player 1 & Player 2:** Two AI agents that receive questions and compete to answer them correctly based on their assigned LLM models.
-   **Communication:** The agents communicate asynchronously using a simple message queue system, allowing them to act independently without blocking each other.

This example is a powerful showcase of how to orchestrate complex, dynamic interactions between multiple AI agents using the PocketFlow-PHP framework.

## Setup & Run

**Prerequisites:** PHP 8.3+ and [Composer](https://getcomposer.org/) must be installed.

1.  **Navigate into this directory:**
    Make sure your terminal is inside the `quiz-show-multi-agent` folder.

2.  **Install Dependencies:**
    Run Composer to install the required packages for this project (PocketFlow core, OpenAI client, etc.).
    ```bash
    composer install
    ```

3.  **Set up API Key:**
    This example uses [OpenRouter.ai](https://openrouter.ai/) to access free LLM models.
    -   Rename the `.env.example` file in this directory to `.env`.
    -   Paste your OpenRouter API key into the `.env` file.

4.  **Run the Show!**
    Execute the main script to start the quiz show.
    ```bash
    php main.php
    ```

You will see the quiz show unfold in your terminal as the agents interact with each other.

## Configuration

### How to Get an OpenRouter API Key

1.  Go to **[OpenRouter.ai](https://openrouter.ai/)** and sign up.
2.  Navigate to your account settings/keys page.
3.  Copy your API key and paste it into the `.env` file as the value for `OPENROUTER_API_KEY`.

### How to Change the AI Models

You can easily change the models for each agent. The models are defined in an array at the top of the `flow.php` file.

**File: `flow.php`**
```php
// ...
        // 2. Define the models for the agents
        $models = [
            'quizmaster' => 'deepseek/deepseek-chat-v3-0324:free',
            'player1' => 'google/gemma-2-27b-it:free',
            'player2' => 'mistralai/mistral-small-3.2-24b-instruct:free',
        ];
// ...
```
Simply replace the model strings with any other compatible model available on OpenRouter.

## How it Works

The application logic is split into three main files, following the PocketFlow philosophy:

-   **`main.php`**: The simple entry point that loads the environment and calls the flow creation function.
-   **`flow.php`**: Defines the overall game logic. It creates the agents, sets up their communication queues, and orchestrates the concurrent execution of their individual flows.
-   **`nodes.php`**: Contains the core logic for each agent (`QuizmasterAgent`, `PlayerAgent`) as `AsyncNode` classes, as well as the `MessageQueue` helper class.
