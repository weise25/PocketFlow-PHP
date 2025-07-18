# PocketFlow-PHP Research Agent

This project is a simple yet powerful research agent built using the PocketFlow-PHP framework. The agent can take a user's query, understand its complexity, perform web searches to gather information, and synthesize a final report based on its findings.

It is self-contained and does not depend on the parent directory. You can copy this folder anywhere on your system, run `composer install`, and it will work.

## Features

-   **Dynamic Task Planning:** The agent analyzes the user's query to decide whether it can answer from its own knowledge or if it needs to perform web research.
-   **Strategic Web Search:** For complex queries, the agent creates a multi-step search plan to gather information efficiently.
-   **Iterative Research:** The agent executes its search plan, gathering data from the Brave Search API.
-   **Report Synthesis:** Once enough information is gathered, the agent synthesizes the search results into a coherent, markdown-formatted report.
-   **Resilient:** Includes logic to handle API rate limits gracefully.

## Setup

To get this project running, follow these steps:

1.  **Install Dependencies:**
    If you haven't already, install the required PHP packages using Composer.
    ```bash
    composer install
    ```

2.  **Create Environment File:**
    Create a `.env` file in the root of the project. This file will hold your secret API keys. You can copy the provided `.env.example` if it exists, or create one from scratch.

3.  **Add API Keys:**
    Open your `.env` file and add your API keys for OpenRouter and Brave Search:
    ```
    OPENROUTER_API_KEY="your_openrouter_api_key_here"
    BRAVE_API_KEY="your_brave_search_api_key_here"
    ```

## Usage

To run the agent, simply execute the `main.php` script from your terminal:

```bash
php main.php
```

The agent will then prompt you to enter your research query. From there, it will show you its decision-making process as it works to answer your query.

