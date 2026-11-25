# Frontend ↔ backend contract

The agreement between **domino-backend** (this repository) and **domino-front**:
the words both sides use, every endpoint, error, channel and event, and the game
model both engines share. It is the single source of truth for both repositories;
domino-front's `AGENTS.md` and `docs/CONVENTIONS.md` point here.

**Changing anything in this file changes both repositories, in the same change
set**: the backend, the frontend, their tests and this file. Both reviewer agents
(`domino-backend-reviewer`, `domino-front-reviewer`) check changes against it.

## 1. Words

One idea, one word — in code, JSON, routes, docs and UI text.

| Word | Means | Not |
| --- | --- | --- |
| **room** | A table people join online: seats, settings, status. `Room` model, `/api/rooms`. | lobby, table (in code), game |
| **match** | Rounds played to a target score (`MatchState`, `MatchConfig`). | game (in code) |
| **round** | One deal, played until a domino or a block (`RoundState`). | hand, game |
| **seat** | A place at the table, numbered from 0 in turn order. Partners are seats 0 and 2, and 1 and 3. | seatIndex, playerIdx, slot |
| **player** | The person or bot in a seat. | user (except for accounts) |
| **hand** | The tiles a seat holds. | rack |
| **boneyard** | Tiles not dealt (2-player games draw from it). | stock (the coach's word for it in UI text is fine) |
| **end** | One of the two open numbers of the board (`leftEnd`, `rightEnd`). | side (a *side* is where a tile goes: `left`, `right`, `start`) |
| **call** | A hint call made with a play or a pass (`HintCall`). | signal |
| **bot seat** | A seat played by the server's bot (`botSeats`). | AI seat |

Game-state keys keep the names they were stored with: `playerIndex` (the
viewer's seat), `currentPlayer`, `firstMover`, `player` (in a history entry),
`matchWinner`, `lastWinner`, `winner` are all **seats**. Saved games (database
and the browser) use these keys, so they are not renamed; new names use *seat*.

## 2. JSON

- **camelCase in both directions**: request bodies, responses, events, and the
  game state (`maxPlayers`, `mySeat`, `tileId`, `passwordConfirmation`,
  `boneyardCount`). Database columns stay snake_case; resources and form
  requests translate at the edge.
- Resources come wrapped in `data`; a redacted `ClientView` comes at the top
  level; nothing to return → `204 No Content`.
- Errors: `{"message": "…", "code": "snake_case"}` (§4). Validation errors keep
  Laravel's `{"message", "errors": {field: [...]}}`, keyed by the camelCase field.
- Seat numbers, ids and timestamps are integers (`turnExpiresAt`, `sentAt`:
  Unix seconds); `createdAt` is an ISO 8601 string.

## 3. Endpoints

All under `/api`; all but register, login and health need `Authorization: Bearer <token>`.

| Method and path | Backend | Frontend (`lib/api.ts`) | Body → answer |
| --- | --- | --- | --- |
| `POST /register` | `AuthController@register` | `api.register` | `{name, email, password, passwordConfirmation}` → `201 {user, token}` |
| `POST /login` | `AuthController@login` | `api.login` | `{email, password}` → `{user, token}` |
| `POST /logout` | `AuthController@logout` | `api.logout` | → `204` |
| `GET /user` | route closure (`UserResource`) | `api.me` | → `{data: User}` |
| `GET /rooms` | `RoomController@index` | `api.rooms.list` | → `{data: Room[]}` (waiting rooms) |
| `POST /rooms` | `RoomController@store` | `api.rooms.create` | `{maxPlayers, settings}` → `201 {data: Room}` |
| `POST /rooms/{room}/join` | `RoomController@join` | `api.rooms.join` | → `{data: Room}` |
| `POST /rooms/{room}/leave` | `RoomController@leave` | `api.rooms.leave` | → `204` |
| `GET /rooms/{room}/state` | `GameController@state` | `api.game.getState` | → `ClientView` |
| `POST /rooms/{room}/play` | `GameController@play` | `api.game.play` | `{tileId, side, call?}` → `ClientView` |
| `POST /rooms/{room}/draw` | `GameController@draw` | `api.game.draw` | → `ClientView` |
| `POST /rooms/{room}/pass` | `GameController@pass` | `api.game.pass` | `{call?}` (only `close_game`) → `ClientView` |
| `POST /rooms/{room}/reclaim` | `GameController@reclaim` | `api.game.reclaim` | → `ClientView` |
| `POST /rooms/{room}/substitute-bot/{seat}` | `GameController@substituteBot` | `api.game.substituteBot` | → `204` |
| `POST /rooms/{room}/chat` | `ChatController@store` | `api.chat.send` | `{message}` (≤ 200 chars) → `204` |
| `GET /health` | route closure | — | → `204`, or `503` |
| `POST /broadcasting/auth` | `Broadcast::auth` | Echo (`lib/echo.ts`) | channel authorization |

Shapes: `User {id, name, email}`; `Room {id, status, maxPlayers: 2|4, playerCount,
settings, mySeat, createdAt}`; `settings {target?: 100|150|200, turnTimer?:
0–300, blockedTiebreak?: 'sum'|'low-player', teams?: boolean, hintCalls?:
boolean}` (the part of `MatchConfig` a room picks); `ClientView` (§6).

## 4. Errors

`{"message", "code"}`. The frontend gets `ApiError {message, status, code, body}`;
UI decisions use `code`, never the message text.

| Code | Status | When |
| --- | --- | --- |
| `unauthenticated` | 401 | No or bad token. |
| `forbidden` | 403 | An action the user may not take. |
| `not_seated` | 403 | The user has no seat in this room. |
| `not_found` | 404 | Unknown room or route. |
| `method_not_allowed` | 405 | Wrong HTTP method. |
| `too_many_requests` | 429 | Rate limit (login, register: 5 per minute; game routes: 120). |
| `room_not_open`, `already_in_room`, `room_full` | 422 | Joining a room. |
| `game_not_in_progress` | 422 | A game route on a room that is not playing. |
| `match_over`, `round_over`, `not_your_turn` | 422 | Any move. |
| `tile_not_in_hand`, `illegal_side`, `illegal_call` | 422 | Playing a tile. |
| `can_play`, `boneyard_empty`, `must_draw` | 422 | Drawing or passing. |
| `no_such_seat`, `cannot_substitute_self`, `seat_already_bot`, `player_connected` | 422 | Bot substitution. |
| `presence_unavailable`, `database_unavailable` | 503 | A dependency is down. |
| `http_error` | other | Any other HTTP error. |

## 5. Real-time (Reverb)

Names are built in one place per side: `App\Broadcasting\Channels` and
`domino-front/lib/channels.ts`. Event names are each event's `broadcastAs()`,
listened for as `EVENTS.*` (with the leading dot Echo needs).

| Channel | Kind | Backend | Frontend | Carries |
| --- | --- | --- | --- | --- |
| `room.{roomId}` | presence | `Channels::room()`, `ROOM_PATTERN` | `roomChannel()` | Members `{id, name}`; `chat.message` |
| `room.{roomId}.seat.{seat}` | private | `Channels::seat()`, `SEAT_PATTERN` | `seatChannel()` | `game.state` for that seat only |
| `public-test` | public, local only | `Channels::TEST` | `TEST_CHANNEL` | `test.ping` |

| Event | Class | Payload |
| --- | --- | --- |
| `game.state` | `GameStateUpdated` | the seat's `ClientView` |
| `chat.message` | `ChatMessageSent` | `ChatMessage {playerIndex, playerName, message, sentAt}` |
| `test.ping` | `TestBroadcast` | `{message}` |

## 6. Game model

The game state is the same model on both sides. TypeScript types live in
`domino-front/types/domino.ts`; PHP classes in `App\Services\Game\Data` and
`…\Enums`. Same names, same keys.

| TypeScript | PHP | Notes |
| --- | --- | --- |
| `Tile` | `Data\Tile` | `{a, b, id}`, id 0–27 |
| `BoardEntry`, `BoardEntryStart`, `BoardEntryPlayed` | `Data\BoardEntry…` | `{tile, side, endA, endB}` / `{tile, side, matchEnd, newExposed}` |
| `HistoryEntry` | `Data\HistoryEntry` | `{player, action, tile?, side?, call?, callNumber?}` |
| `RoundResult…` | `Data\RoundResult…` | `kind: 'domino' \| 'blocked'` |
| `MatchConfig` | `Data\MatchConfig` | `partner` is frontend-only (local games) |
| `RoundState`, `MatchState` | `Data\RoundState`, `Data\MatchState` | the server adds `turnToken`, `turnExpiresAt`, `botSeats`, `forfeitedSeats`, `lastRoundResult`; `openingDouble` (below) |
| `ClientView` | `Data\ClientView` | one seat's redacted view (`Redactor`) |
| `Side`, `HintCall`, `Difficulty`, `Opponents`, `blockedTiebreak` | `Enums\Side`, `HintCall`, `Difficulty`, `Opponents`, `BlockedTiebreak` | same string values |
| `Room['status']` | `App\Enums\RoomStatus` | `waiting`, `playing`, `finished` |

### Rules both engines implement

The frontend plays local games itself; the server is the authority online. The
same rule has the same name on both sides — change both together.

**Opening.** In the first round of a match the holder of the highest double
(or, when no double was dealt, of the heaviest tile) opens (`firstMover`),
with any tile they like. That tile is `openingDouble` on `RoundState` and
`ClientView`, public for the whole round: until it is played, everyone knows
the opener holds it. Later rounds are opened by the last
winner, and `openingDouble` is null.

| domino-front | domino-backend |
| --- | --- |
| `lib/engine.ts` | `App\Services\Game\GameEngine` |
| `lib/ai.ts` (calls part) | `App\Services\Game\HintCallAdvisor` |
| `lib/ai.ts` (`pickMove`) | `App\Services\BotPlayer` |
| `lib/tableView.ts` (`clientViewToRound`) | `App\Services\Game\Redactor` (the other way) |

Shared names: `makeFullSet`, `isDouble`, `tilePips`, `tileHas`, `handPips`,
`handIsValid`, `shuffle`, `deal`, `findHighestDoubleStarter`, `initRound`,
`initMatch`, `legalSidesForTile`, `canPlayAnyTile`, `legalCalls`,
`legalPassCalls`, `endsAreDead`, `passedNumbers`, `exposedNumber`, `playTile`,
`passTurn`, `drawTile`, `computeRoundResult`, `applyRoundResult`,
`startNextRound`, `chooseCall`, `choosePassCall`, `callScore`, `passScore`,
`latestCalls`.

## 7. Changing the contract

1. Change the backend (route, resource, request, event, data class) and its tests.
2. Change the frontend (`lib/api.ts`, `lib/channels.ts`, `types/domino.ts`, the
   callers) and its tests.
3. Update this file, the backend `README.md` API table, and domino-front's `CHANGELOG.md`.
4. Run both reviewers; commit both repositories together, and deploy them together.
