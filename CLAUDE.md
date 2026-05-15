# Domino Backend - AI Assistant Guidelines

## 🚀 Project Overview
This is the Laravel 13 backend for the Domino double-six multiplayer game. It provides the REST API for room management and the WebSocket layer (Laravel Reverb) for real-time game state synchronization.

## 🛠️ Essential Commands
- **Start API Server**: `php artisan serve`
- **Start WebSockets**: `php artisan reverb:start`
- **Start Queue (for events)**: `php artisan queue:work`
- **Run Migrations**: `php artisan migrate`
- **Clear Cache**: `php artisan optimize:clear`

## 🏗️ Architecture & Conventions

### 1. Game State Management
- **Room Model**: Contains a JSON column `match_state` which holds the entire canonical state of the game (equivalent to `MatchState` in the frontend).
- **Redaction**: The server MUST NEVER send the full `match_state` to a client. Before broadcasting, the state must be passed through a Redaction Service (`App\Services\Game\RedactorService`) that strips opponents' hands and returns a `ClientView`.
- **Validation**: Every incoming action from a player must be validated against the server's canonical `match_state` before applying it.

### 2. Real-Time Sync (Laravel Reverb)
- **Events**: Game state updates should be broadcast using Laravel Events that implement `ShouldBroadcastNow`.
- **Channels**: Use Presence Channels (`room.{id}`) so the server knows exactly who is currently connected and sitting at the table.
- **Payloads**: Broadcast payloads should only contain the specific `ClientView` for the user receiving it, or use Whispers / targeted events if needed.

### 3. API Design
- Use **Laravel Sanctum** for SPA / Token authentication.
- API endpoints should return clean JSON resources (`php artisan make:resource`).
- Keep Controllers thin. Offload complex game engine logic to `App\Services\Game` classes.

### 4. Code Style
- **Strict Typing**: Use `declare(strict_types=1);` in all new PHP files.
- **PHP 8.2+ Features**: Utilize readonly properties, enums, and match expressions.
- **Naming**: Use camelCase for methods/variables, PascalCase for classes, snake_case for DB columns.
