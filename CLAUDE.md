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
- **Redaction**: The server MUST NEVER send the full `match_state` to a client. Before broadcasting, the state must be passed through a Redaction Service (`App\Services\Game\Redactor`) that strips opponents' hands and returns a `ClientView`. (Exception by design: `lastRoundResult.finalHands` holds the *finished* round's leftover hands, which are public once that round is scored.)
- **Validation**: Every incoming action from a player must be validated against the server's canonical `match_state` before applying it.

### 2. Real-Time Sync (Laravel Reverb)
- **Events**: Game state updates should be broadcast using Laravel Events that implement `ShouldBroadcastNow`.
- **Channels**: Use Presence Channels (`room.{roomId}`) so the server knows exactly who is currently connected and sitting at the table; game state goes to each seat's private channel `room.{roomId}.seat.{seat}` (names and patterns built by `App\Broadcasting\Channels`).
- **Payloads**: Broadcast payloads should only contain the specific `ClientView` for the user receiving it, or use Whispers / targeted events if needed.

### 3. API Design
- Use **Laravel Sanctum** for SPA / Token authentication.
- API endpoints should return clean JSON resources (`php artisan make:resource`).
- Keep Controllers thin. Offload complex game engine logic to `App\Services\Game` classes. Every state change ends in `MatchSettler::settle()`.
- Errors are thrown as `ApiException` / `InvalidMoveException` and answered as `{message, code}`; endpoints with nothing to return answer `204 No Content`.

### 4. Code Style
The full conventions — layers, PHP, naming, HTTP API, database, broadcasting,
comments, tests and commits — are in `docs/CONVENTIONS.md`, and the agreement
with domino-front (words, endpoints, errors, channels, game model) is in
`docs/CONTRACT.md`. Read both before writing code. In short:
- **Strict Typing**: `declare(strict_types=1);` in every PHP file (arch test).
- **PHP 8.3 Features**: readonly value objects, enums, `match`, constructor promotion.
- **Naming**: camelCase for methods/variables and every JSON key, PascalCase for classes, snake_case for DB columns.

## ✅ Before every commit
1. `vendor/bin/pint --test` and `php artisan test` pass (the suite includes the
   architecture rules in `tests/Unit/ArchTest.php`).
2. Launch the `domino-backend-reviewer` agent (`.claude/agents/domino-backend-reviewer.md`)
   on the staged change and fix every "must fix" finding (or say why one doesn't
   apply). Commit only after it reports PASS or PASS WITH NITS.
