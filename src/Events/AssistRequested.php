<?php

namespace Packstub\SessionReplay\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Packstub\SessionReplay\Models\AssistRequest;

/**
 * A new assist request: a visitor asked for help ($request->opened_by "visitor"), or an agent offered it from a live
 * session ("agent"). Where an app tells its support team (a notification, a chat message).
 */
class AssistRequested
{
    use Dispatchable;

    public function __construct(public AssistRequest $request) {}
}
