<?php

declare(strict_types=1);

// Architecture rules from docs/CONVENTIONS.md that a test can check.

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\Game\Data\MatchState;
use App\Services\Game\Data\RoundState;
use App\Services\PresenceChecker;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Resources\Json\JsonResource;

arch('PHP basics: no debugging calls or unsafe functions')->preset()->php();

arch('no Laravel dump helpers (the PHP preset covers the rest)')
    ->expect(['dd', 'ddd'])
    ->not->toBeUsed();

arch('app code declares strict types')
    ->expect('App')
    ->toUseStrictTypes();

arch('env() is read only in config files')
    ->expect('env')
    ->not->toBeUsed();

arch('game rules stay pure: no framework, database, HTTP or events')
    ->expect('App\Services\Game')
    ->not->toUse(['Illuminate', 'App\Models', 'App\Http', 'App\Events', 'App\Jobs', 'App\Actions']);

arch('game rules use no framework helpers')
    ->expect(['now', 'today', 'app', 'config', 'cache', 'event', 'broadcast', 'dispatch', 'request', 'response', 'logger'])
    ->not->toBeUsedIn('App\Services\Game');

arch('classes are final, except the abstract controller, the base API exception and the faked presence checker')
    ->expect('App')
    ->classes()
    ->toBeFinal()
    ->ignoring([Controller::class, ApiException::class, PresenceChecker::class]);

arch('game value objects are readonly; the match and round state are mutable on purpose')
    ->expect('App\Services\Game\Data')
    ->classes()
    ->toBeReadonly()
    ->ignoring([MatchState::class, RoundState::class]);

arch('enums are string-backed')
    ->expect(['App\Enums', 'App\Services\Game\Enums'])
    ->toBeStringBackedEnums();

arch('actions are named XxxAction and run through execute()')
    ->expect('App\Actions')
    ->toHaveSuffix('Action')
    ->toHaveMethod('execute');

arch('controllers are named XxxController and extend the base controller')
    ->expect('App\Http\Controllers')
    ->toHaveSuffix('Controller')
    ->toExtend(Controller::class)
    ->ignoring(Controller::class);

arch('controllers leave the database to models and actions')
    ->expect('App\Http\Controllers')
    ->not->toUse('Illuminate\Support\Facades\DB');

arch('form requests are named XxxRequest')
    ->expect('App\Http\Requests')
    ->toHaveSuffix('Request')
    ->toExtend(FormRequest::class);

arch('resources are named XxxResource')
    ->expect('App\Http\Resources')
    ->toHaveSuffix('Resource')
    ->toExtend(JsonResource::class);

arch('jobs are queued and named XxxJob')
    ->expect('App\Jobs')
    ->toHaveSuffix('Job')
    ->toImplement(ShouldQueue::class);

arch('events broadcast immediately')
    ->expect('App\Events')
    ->toImplement(ShouldBroadcastNow::class);
