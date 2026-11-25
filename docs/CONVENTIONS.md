# Code conventions

How code in this repository is written, so it reads as if one person wrote all of
it. New code follows these rules; when you touch old code that doesn't, bring the
lines you change into line.

Rules marked **(arch)** are checked by `tests/Unit/ArchTest.php` (Pest
architecture tests, part of `php artisan test`); **(pint)** by `vendor/bin/pint --test`
(`pint.json`). The `domino-backend-reviewer` agent
(`.claude/agents/domino-backend-reviewer.md`) checks the rest before every commit.

## 1. Architecture: where code lives

A request flows **route → controller → (form request, policy) → action → game
rules → model → resource / broadcast**. Each layer has one job.

| Layer | Namespace | Does | Must not |
| --- | --- | --- | --- |
| Routes | `routes/api.php`, `routes/channels.php` | Map URLs to controller methods; middleware. Closures only for short health, auth and local-only glue. `routes/channels.php` holds the channel authorization callbacks (find the room, check the seat), nothing more. | Contain game logic. |
| Controllers | `App\Http\Controllers` | Take the request, call one action, return a resource or response. Extend the base `Controller` **(arch)**. | Change game state, build `MatchConfig`, loop over seats, use the `DB` facade **(arch)**. |
| Form requests | `App\Http\Requests` | All input validation for an endpoint that takes input. | Query game state (that's the action's job). |
| Resources | `App\Http\Resources` | Shape every model or data object sent over HTTP. | Leak hidden or other players' data. |
| Actions | `App\Actions\{Domain}` | One use case each (`PlayTileAction`), run through `execute()` **(arch)**: load under the room lock, check rules, apply. | Return HTTP responses. |
| Game rules | `App\Services\Game` | Pure, deterministic rules: engine, hint calls, bots, redaction. Static methods are fine here. | Touch the database, HTTP, facades, events, or helpers like `now()`, `app()`, `config()`, `event()` **(arch)**. |
| Game data | `App\Services\Game\Data`, `…\Enums` | Value objects with `fromArray()` / `toArray()` (camelCase keys, the same as the frontend's types). | Hold behaviour beyond small derived values. |
| App services | `App\Services` | Infrastructure around the game: settling a turn (`MatchSettler`), broadcasting, presence, turn timers, finalizing a match. Instance methods, constructor-injected. | Hold game rules. |
| Models | `App\Models` | Columns, casts, relations, small queries about themselves, `Room::withLock()`. | Hold game rules. |
| Events, jobs | `App\Events`, `App\Jobs` | Broadcast payloads (`ShouldBroadcastNow` **(arch)**); queued work (`TurnTimeoutJob`, `ShouldQueue` **(arch)**). | Duplicate flows that already exist in an action or service. |

- **One flow, one place.** If two callers (a controller and a job) do the same
  sequence, it lives in one action or service that both call. Every state change
  ends in `MatchSettler::settle()` (bots play, then finalize, or arm the timer and
  save); callers broadcast after the lock is released.
- **Never trust the client.** Every move is validated against the stored
  `match_state` under `Room::withLock()`; the client never receives another
  player's hand except through `Redactor` (see `CLAUDE.md`).
- **Dependencies are injected** (constructor or method injection). Don't `new`
  up a service or action inside a controller, and don't call `app()` in app
  code. Pure game-rule classes are called statically.
- **Enums**: game enums in `App\Services\Game\Enums`, app-level enums
  (`RoomStatus`) in `App\Enums`. Backed by strings, cases in PascalCase.

## 2. PHP

- `declare(strict_types=1);` in every PHP file — app code **(arch)**, and routes,
  migrations, factories, seeders and tests too. Framework skeleton files
  (`config/`, `bootstrap/`, `public/index.php`) are left as Laravel ships them.
- Every class is `final` **(arch)**, except the abstract base `Controller`, the
  base `ApiException`, and `PresenceChecker` (tests fake it with a subclass).
  Value objects are `final readonly` classes **(arch)**; `MatchState` and
  `RoundState` are the mutable game state and say so in their docblock.
- Constructor property promotion, `readonly` where the value never changes.
- Every parameter, property and return has a type. Arrays get a PHPDoc shape
  when the type isn't obvious: `@return array<int, array{delta: int, newElo: int}>`,
  `@param list<int> $seats`.
- Named arguments when calling a constructor with more than three parameters
  or with booleans: `new MatchConfig(numPlayers: 4, teams: true, …)`.
- `match` over `switch`; early returns over nested `if`s; `===` always.
- Import every class (`use RuntimeException;`), never `\RuntimeException` inline
  **(pint)**. Imports sorted alphabetically **(pint)**.
- No `dd()`, `dump()`, `var_dump()`, `ray()`, `print_r()` left in code, and
  `env()` only in `config/` **(arch)**. Code style is Pint's Laravel preset
  **(pint)**.

## 3. Naming

- Classes `PascalCase`, named for their role: `XxxController`, `XxxRequest`,
  `XxxResource`, `XxxAction`, `XxxJob` **(arch)**; events in the past tense
  (`GameStateUpdated`, `ChatMessageSent`). Services are named for what they do,
  with no `Service` suffix: `GameEngine`, `Redactor`, `TurnTimer`,
  `MatchFinalizer`, `PresenceChecker`.
- Methods and variables `camelCase`; DB columns and JSON request fields
  `snake_case` (`max_players`, `tile_id`); game-state JSON keys `camelCase`
  (they mirror the frontend's TypeScript types).
- Model methods: relations as nouns (`players()`), questions as `isX()`/`hasX()`,
  lookups as plain nouns or `findX()` — not `getX()` (`seatNames()`, not
  `getSeatNames()`).
- Broadcast names `domain.event` (`game.state`, `chat.message`, `test.ping`);
  channels `room.{roomId}` (presence) and `room.{roomId}.seat.{seatIndex}`
  (private).
- Booleans read as questions: `$matchOver`, `$isBot`, `$hasCall`.

## 4. HTTP API

- Every endpoint that takes input has a form request. Rules are arrays
  (`['required', 'integer']`), never pipe strings; allowed values use
  `Rule::enum()` or `Rule::in()`.
- Every route that needs a seat or a playing room checks it the same way — one
  shared check, not a hand-written `if` per method.
- Success responses return a resource (`RoomResource`) or the data object's
  `toArray()` (the redacted `ClientView`). Responses with nothing to return use
  `204 No Content`.
- Errors: `422` for rule violations, `403` for "not your seat", `404` for
  unknown ids, `503` when a dependency is down. The body is
  `{"message": "…", "code": "snake_case_reason"}` — `message` for people, `code`
  for the client. Throw `ApiException` (or `InvalidMoveException` for game rule
  errors) with a code; `bootstrap/app.php` renders it. Never build error JSON by
  hand. Validation errors keep Laravel's `{"message", "errors"}` shape.
- Messages are full sentences in plain English ending with a full stop:
  "It is not your turn."

## 5. Database and concurrency

- Every read-modify-write of `match_state` or the seat list goes through
  `Room::withLock()`. Broadcast and dispatch jobs **after** the transaction
  commits (`->afterCommit()`, or after `withLock()` returns).
- Migrations: anonymous classes, a short docblock when the reason isn't obvious,
  a working `down()`. Never change what a migration that has run does (its
  schema or data); add a new migration instead.
- Models declare fillable and hidden fields with attributes
  (`#[Fillable([...])]`, `#[Hidden([...])]`) and casts in `casts()`.

## 6. Broadcasting

- Events implement `ShouldBroadcastNow` **(arch)**, take their data through
  promoted `public readonly` constructor properties, and define, in this order:
  `broadcastOn()`, `broadcastAs()`, `broadcastWith()`.
- A broadcast never carries another player's hidden tiles: game state goes out
  per seat on the private seat channel, as a redacted `ClientView`.

## 7. Comments

- Every class has a docblock saying what it is for (one to three lines); public
  methods get one when the name doesn't say everything.
- Comments say *why*. Plain English, present tense, no "we"/"I", no TODOs left
  in commits, no commented-out code, no framework boilerplate comments
  ("Bootstrap any application services.").
- Section dividers inside a class are not used; split the class instead.

## 8. Tests (Pest)

- Pest syntax everywhere: `it('rejects a move out of turn', function () { … })`
  with `expect()`. New tests never use PHPUnit test classes; old unit tests move
  to Pest when they are next changed.
- `tests/Feature` for anything through HTTP, jobs or the database
  (`uses(RefreshDatabase::class)`); `tests/Unit` for pure game rules.
- Test names read as a sentence about behaviour, starting with a verb:
  `it('assigns seats in join order …')`. A request the API turns down is
  "rejects …" (not "refuses" or "throws").
- Helpers shared by several files go in `tests/Pest.php`; helpers for one file
  are functions before its first test, named so they can't clash with another
  file's (Pest loads every file into one process). Build state with the real
  engine (`GameEngine::initMatch()`) and factories, not hand-written arrays.
- A new feature comes with tests for each behaviour; a bug fix with a test that
  fails without it. Feature tests assert the status code **and** the JSON shape.

## 9. Commits and docs

- Subject: `type: what changed` in the imperative, lower case, no full stop, at
  most 72 characters. Types: `feat`, `fix`, `refactor`, `perf`, `test`, `docs`,
  `chore`, `style` (optional scope: `fix(deps): …`).
- User-visible API changes are reflected in `README.md`; architecture changes in
  `CLAUDE.md` and this file.
- Before committing: `vendor/bin/pint --test` and `php artisan test` pass, then
  the `domino-backend-reviewer` agent reports PASS or PASS WITH NITS.
