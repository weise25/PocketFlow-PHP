# Example: Text-to-Resume Agent with Frontend

This directory contains a **standalone, fully functional web application** built with **PocketFlow-PHP**. It features an AI-powered CV/resume generator with a modern, responsive web interface that transforms natural language descriptions into professional PDF resumes.

This project is self-contained and does not depend on the parent directory. You can copy this folder anywhere on your system, run `composer install`, and it will work.

## Proof of Concept

**This is a proof of concept that shows how easy you can add a frontend to your PocketFlow-PHP Agents!** 

This example demonstrates that PocketFlow-PHP isn't just for command-line applications - you can seamlessly integrate modern web frontends with your AI workflows. The combination of:

- **Backend AI Workflows:** Powered by PocketFlow's node-based architecture
- **Modern Web Frontend:** Responsive, real-time interface with streaming capabilities
- **Simple Integration:** Clean API layer connecting frontend and backend

Shows how quickly you can transform a command-line AI agent into a full-featured web application. The entire frontend is contained in a single HTML file (`frontend/index.html`) that includes all CSS and JavaScript, making it incredibly portable and easy to deploy.

## Overview

-   **AI-Powered CV Generation:** Users describe their desired resume in natural language, and the AI creates a structured CV plan using the ReAct framework (Observation, Thought, Action).
-   **Interactive Web Interface:** Modern, responsive frontend with real-time streaming, dark/light theme toggle, and step-by-step progress visualization.
-   **Live Preview & Editing:** Users can review the AI's plan in real-time and request modifications before final generation.
-   **Professional PDF Output:** Converts the structured CV data into a PDF document ready for download.
-   **Streaming Architecture:** Uses Server-Sent Events (SSE) for real-time communication between frontend and backend.

This example demonstrates how to build a complete web application with PocketFlow-PHP, showcasing both the framework's workflow capabilities and its integration with modern web technologies.

## Features

### Modern Web Interface
- **Responsive Design:** Works perfectly on desktop, tablet, and mobile devices
- **Dark/Light Theme:** Toggle between themes with persistent user preference
- **Real-time Streaming:** Watch the AI think and generate content in real-time
- **Progress Visualization:** Clear step-by-step progress indicators with animations
- **Lucide Icons:** Consistent, professional iconography throughout

### AI-Powered Workflow
- **Natural Language Input:** Describe your CV requirements in plain English
- **ReAct Framework:** AI uses Observation → Thought → Action methodology
- **Interactive Editing:** Request changes and see updates in real-time
- **YAML Structure:** Structured data format for consistent CV generation
- **Error Handling:** Robust error recovery and user-friendly messages

### Professional Output
- **PDF Generation:** High-quality PDF documents using DomPDF
- **Customizable Design:** AI adapts styling based on user requirements
- **Single-Page Format:** Compact layouts that fit on one page

## Setup & Run

**Prerequisites:** PHP 8.3+ and [Composer](https://getcomposer.org/) must be installed.

1.  **Navigate into this directory:**
    Make sure your terminal is inside the `text-to-resume-agent` folder.

2.  **Install Dependencies:**
    Run Composer to install the required packages for this project (PocketFlow core, OpenAI client, DomPDF, etc.).
    ```bash
    composer install
    ```

3.  **Set up API Key:**
    This example uses [OpenRouter.ai](https://openrouter.ai/) to access LLM models.
    -   Rename the `.env.example` file in this directory to `.env`.
    -   Paste your OpenRouter API key into the `.env` file.

4.  **Start the Web Server:**
    Use PHP's built-in development server to serve the frontend directory.
    ```bash
    php -S localhost:8000 -t frontend
    ```

5.  **Open in Browser:**
    Navigate to `http://localhost:8000` in your web browser to start using the CV generator.

## Configuration

### How to Get an OpenRouter API Key

1.  Go to **[OpenRouter.ai](https://openrouter.ai/)** and sign up.
2.  Navigate to your account settings/keys page.
3.  Copy your API key and paste it into the `.env` file as the value for `OPENROUTER_API_KEY`.

### How to Change the AI Model

You can easily change the LLM model used for CV generation. The model is defined in the `.env` file.

**File: `.env`**
```env
OPENROUTER_API_KEY="your-api-key-here"
LLM_NAME="deepseek/deepseek-chat-v3-0324:free"
```

Simply replace the `LLM_NAME` value with any other compatible model available on OpenRouter. Popular free options include:
- `google/gemma-3-27b-it:free`
- `moonshotai/kimi-k2:free`
- `mistralai/mistral-small-3.2-24b-instruct:free`
## How it Works

The application follows the PocketFlow philosophy with a clear separation of concerns:

### Backend Architecture

-   **`main.php`**: Command-line entry point for testing the workflow independently.
-   **`flow.php`**: Defines the CV generation workflow using PocketFlow nodes and transitions.
-   **`nodes.php`**: Contains the core logic for each step of the CV generation process.
-   **`api.php`**: Web API endpoint that handles HTTP requests and manages streaming responses.

### Frontend Architecture

-   **`frontend/index.html`**: Complete web application in a single file containing HTML, CSS, and JavaScript with modern responsive design and real-time streaming capabilities.

### Utility Functions

-   **`utils/llm_api.php`**: OpenRouter API integration with streaming support.
-   **`utils/pdf_converter.php`**: HTML-to-PDF conversion using DomPDF library.

## Workflow Steps

### 1. User Input
Users describe their desired CV in natural language, providing details about:
- Professional role and experience level
- Key skills and technologies
- Design preferences
- Industry focus

### 2. AI Planning (ReAct Framework)
The AI analyzes the request using structured thinking:
- **Observation:** Understanding the user's requirements
- **Thought:** Planning the CV structure and content
- **Action:** Generating the structured CV plan

### 3. Review & Edit
Users can:
- Review the generated plan in real-time
- Request modifications with natural language
- Approve the plan when satisfied

### 4. PDF Generation
The system:
- Converts the structured plan to HTML
- Applies professional styling
- Generates a downloadable PDF

## Technical Highlights

### Real-time Streaming
- **Server-Sent Events (SSE):** Enables real-time communication
- **Chunked Responses:** Stream LLM output as it's generated
- **Live Updates:** Dynamic UI updates during processing

### Error Handling
- **YAML Validation:** Automatic fixing of common formatting issues
- **Graceful Degradation:** User-friendly error messages
- **Retry Logic:** Built-in retry mechanisms for API calls

### Responsive Design
- **Mobile-First:** Optimized for all screen sizes
- **Touch-Friendly:** Large buttons and intuitive gestures
- **Accessibility:** High contrast ratios and keyboard navigation

## Example Usage

1. **Describe Your CV:**
   ```
   "A modern, single-page CV for a senior software engineer with 10+ years 
   of experience, focusing on backend development with Go and Python. 
   Use a clean, professional design with subtle accent colors."
   ```

2. **AI Generates Plan:**
   The AI creates a structured plan with sections for header, summary, 
   experience, skills, education, and more.

3. **Review & Edit:**
   Request changes like "Add a section for volunteer experience" or 
   "Make the design more colorful."

4. **Approve, Generate & Download:**
   Approve the plan and let the Agent generate a PDF file ready for download.

## Customization

### Adding New CV Sections
Modify the CV keywords array in the frontend JavaScript to track additional sections:
```javascript
const cvKeywords = ['header', 'professional_summary', 'experience', 
                   'education', 'skills_sections', 'your_new_section'];
```

### Styling Modifications
Update the CSS variables in the frontend to change colors, fonts, or layouts:
```css
:root {
    --accent-primary: #your-color;
    --bg-primary: #your-background;
}
```

### Custom Prompts
Modify the system prompts in `api.php` to change how the AI generates CVs:
```php
$system_prompt = "Your custom instructions for CV generation...";
```

## Dependencies

- **PocketFlow-PHP:** Core workflow framework
- **OpenAI PHP Client:** LLM API integration
- **DomPDF:** PDF generation
- **Symfony YAML:** YAML parsing and validation
- **Lucide Icons:** Modern icon library
- **Inter Font:** Professional typography



---
