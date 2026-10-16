<?php

namespace Packstub\SessionReplay\Facades;

use Illuminate\Support\Facades\Facade;
use Packstub\SessionReplay\SessionReplayManager;

/**
 * @method static \Packstub\SessionReplay\SessionReplayManager recordWhen(\Closure $callback)
 * @method static \Packstub\SessionReplay\SessionReplayManager userUsing(\Closure $callback)
 * @method static \Packstub\SessionReplay\SessionReplayManager tenantUsing(\Closure $callback)
 * @method static \Packstub\SessionReplay\SessionReplayManager impersonatorUsing(\Closure $callback)
 * @method static \Packstub\SessionReplay\SessionReplayManager propertiesUsing(\Closure $callback)
 * @method static \Packstub\SessionReplay\SessionReplayManager urlUsing(\Closure $callback)
 * @method static \Packstub\SessionReplay\SessionReplayManager logUrlUsing(\Closure $callback)
 * @method static string|null logUrlFor(\Packstub\SessionReplay\Models\ReplayMarker $marker)
 * @method static \Packstub\SessionReplay\SessionReplayManager visibleUsing(\Closure $callback)
 * @method static \Illuminate\Database\Eloquent\Builder visibleTo(\Illuminate\Database\Eloquent\Builder $query, mixed $viewer = null)
 * @method static bool shouldRecord(\Illuminate\Http\Request|null $request = null)
 * @method static \Illuminate\Support\HtmlString recorder(array $options = [])
 * @method static \Illuminate\Support\HtmlString playerAssets()
 * @method static bool check(\Packstub\SessionReplay\Models\ReplaySession|null $session = null, mixed $user = null)
 * @method static string|null urlFor(\Packstub\SessionReplay\Models\ReplaySession $session)
 * @method static string route(string $name, array $parameters = [])
 *
 * @see SessionReplayManager
 */
class SessionReplay extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SessionReplayManager::class;
    }
}
