# Personal Task Manager

A personal planner that pulls coursework from **Canvas LMS** and events from **Google Calendar**, lets you schedule tasks by dragging across a calendar, and includes an **AI assistant running on a local LLM (Ollama)** that can read your schedule and propose changes.

The assistant never edits data on its own: it proposes an action, you approve it, and the server re-validates the proposal before executing it and records the outcome in an action log.

---

## Features

- **Calendar planning:** drag across time to create tasks, events, and routines; drag deadlines and imported events to reschedule.
- **Canvas LMS integration:** syncs courses and assignments through the Canvas REST API (token-based).
- **Google Calendar import:** connects with Google OAuth and imports events, including recurring ones.
- **Today panel and dashboard:** due-soon strip, needs-attention list, and a School page.
- **Pomodoro focus timer.**
- **AI assistant (local, private):**
    - Read tools: list tasks, view the schedule, list coursework, routines, and recent changes.
    - Proposal tools: create, update, complete, or delete tasks; plan focus sessions; create, change, or delete routines.
    - Streams replies to the UI.
    - Human-in-the-loop: every change is a proposal the user approves; the executor re-checks it against current data and writes what happened to the action log.

## Architecture

```
React + TypeScript (Inertia)  ──►  Laravel controllers
                                     ├── Services/Canvas          CanvasClient, CanvasSync
                                     ├── Services/GoogleCalendar  OAuth client, event importer, recurrence parser
                                     ├── Services/Assistant       OllamaClient, AssistantTools, ProposalExecutor, ActionLog
                                     └── Services/Pomodoro
                                   Eloquent models  ──►  SQLite (default)
```

## Tech stack

| Layer    | Technology                                                           |
| -------- | -------------------------------------------------------------------- |
| Backend  | PHP 8.4, Laravel 13, Fortify (auth), Socialite (Google OAuth)        |
| Frontend | React 19, TypeScript, Inertia.js, Tailwind CSS 4, Vite               |
| AI       | Ollama (local LLM) with tool calling                                 |
| Quality  | Pest/PHPUnit feature tests, Vitest, PHPStan (Larastan), Laravel Pint |
| CI       | GitHub Actions: lint, type checks, and tests on every push           |

## Getting started

### Prerequisites

- PHP 8.4+, Composer
- Node.js 22+, npm
- [Ollama](https://ollama.com) running locally with a tool-calling model (default `qwen2.5:7b`)

### Setup

```bash
git clone https://github.com/melihbed/Personal-Task-Manager.git
cd Personal-Task-Manager
composer setup            # installs dependencies, creates .env, generates the app key, migrates (SQLite), builds assets
```

Set these in `.env` as needed:

```env
OLLAMA_MODEL=qwen2.5:7b         # any local model that supports tool calling
GOOGLE_CLIENT_ID=...
GOOGLE_CLIENT_SECRET=...
GOOGLE_REDIRECT_URI=...
```

Canvas and Google Calendar are connected from the **Integrations** page (Canvas uses a personal access token).

### Run

```bash
composer dev              # runs `php artisan dev` (app server and Vite)
```

### Test

```bash
composer test             # lint check, type check, then the test suite
composer ci:check         # frontend checks + everything above (what CI runs)
```

## Design decisions

- **Approval before action.** LLM output is treated as a suggestion, not a command. Proposals are re-validated at execution time because the planner may have changed since the assistant suggested them.
- **Local model.** The assistant runs on Ollama so personal schedule data never leaves the machine.
- **Imported events become the app's own.** Google Calendar is a one-time import, so edits in the app don't fight with a remote sync.
