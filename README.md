# Domino Backend

Laravel 13 REST API and WebSocket server for the **Domino Double-Six** multiplayer game.

---

## Tech Stack

| Layer | Technology |
|---|---|
| Framework | Laravel 13 (PHP 8.3+) |
| Authentication | Laravel Sanctum (token-based) |
| Real-time | Laravel Reverb (WebSockets) |
| Database | MySQL / SQLite |
| Queue | Laravel Queue (sync / database driver) |
| Testing | Pest 4 |

---

## Getting Started

### Prerequisites

- PHP 8.3+
- Composer
- A running MySQL instance (or SQLite for local dev)

### Install & Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

### Running the Stack

You need three processes running simultaneously:

```bash
# HTTP API
php artisan serve

# WebSocket server (Laravel Reverb)
php artisan reverb:start

# Queue worker (for async events / bot turns)
php artisan queue:work
```

Or use the all-in-one dev script:

```bash
composer dev
```

---

## API Reference

All endpoints (except `/register`, `/login`, and `/health`) require a `Bearer` token via Laravel Sanctum.

### Auth

| Method | Endpoint | Description |
|---|---|---|
| `POST` | `/api/register` | Register a new user |
| `POST` | `/api/login` | Obtain a Sanctum token |
| `POST` | `/api/logout` | Revoke current token |
| `GET` | `/api/user` | Get authenticated user |

### Rooms

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/api/rooms` | List available rooms |
| `POST` | `/api/rooms` | Create a new room |
| `POST` | `/api/rooms/{room}/join` | Join a room |
| `POST` | `/api/rooms/{room}/leave` | Leave a room |

### Game Actions

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/api/rooms/{room}/state` | Get the current redacted game state |
| `POST` | `/api/rooms/{room}/play` | Play a tile |
| `POST` | `/api/rooms/{room}/draw` | Draw a tile from the boneyard |
| `POST` | `/api/rooms/{room}/pass` | Pass turn (when no valid move or draw) |
| `POST` | `/api/rooms/{room}/substitute-bot/{seatIndex}` | Replace a disconnected player with a bot |

### Chat

| Method | Endpoint | Description |
|---|---|---|
| `POST` | `/api/rooms/{room}/chat` | Send a chat message in a room |

### Health

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/api/health` | Database connectivity check |

---

## WebSocket Channels

Authentication for private/presence channels goes through `/api/broadcasting/auth` (Sanctum token required).

| Channel | Type | Description |
|---|---|---|
| `room.{id}` | Presence | Tracks who is connected to a room; carries game state events |
| `room.{roomId}.seat.{seatIndex}` | Private | Per-seat channel for sending redacted state only to its owner |

### Key Broadcast Events

- `GameStateUpdated` — fired after every validated game action; carries the per-seat `ClientView`
- `ChatMessageSent` — fired when a player sends a chat message

---

## Architecture

### Game State

- The `Room` model holds a JSON `match_state` column that is the single source of truth for the entire game state.
- `GameEngine` contains all validation and state-transition logic.
- `RedactorService` strips opponent hands before any state is sent to a client, producing a `ClientView`.
- Actions live in `App\Actions\Game` (`PlayTileAction`, `DrawTileAction`, `PassTurnAction`).

### Real-time Flow

```
Client HTTP action → Controller → Action → GameEngine (validate + apply)
    → broadcast GameStateUpdated → Reverb → RedactorService per seat
    → each client receives only its own ClientView
```

### Bot Players

`BotPlayer` service handles automated turns for AI opponents. `TurnTimeoutJob` triggers bot moves when a human player's turn timer expires.

---

## Testing

```bash
composer test
```

Uses **Pest 4** with the Laravel plugin. Integration tests hit a real database — do not mock the DB layer.

---

## Environment Variables (key ones)

| Variable | Description |
|---|---|
| `APP_ENV` | `local` / `production` |
| `DB_CONNECTION` | `mysql` or `sqlite` |
| `REVERB_APP_ID` | Reverb application ID |
| `REVERB_APP_KEY` | Reverb application key |
| `REVERB_APP_SECRET` | Reverb application secret |
| `REVERB_HOST` | Host Reverb listens on (default `127.0.0.1`) |
| `REVERB_PORT` | Port Reverb listens on (default `8080`) |
| `QUEUE_CONNECTION` | `sync` for local, `database` for production |

---

## Code Conventions

- `declare(strict_types=1)` in every PHP file.
- PHP 8.3+ features: readonly properties, enums, match expressions.
- Thin controllers — game logic lives in `App\Services\Game` and `App\Actions\Game`.
- DB columns: `snake_case`. Methods/variables: `camelCase`. Classes: `PascalCase`.
