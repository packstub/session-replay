<?php

namespace Packstub\SessionReplay\Tests\Fixtures\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

/** Staff on a guard of their own (as a Filament panel on an "admin" guard), with ids that collide with users'. */
class Admin extends Authenticatable
{
    protected $guarded = [];
}
