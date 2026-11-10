<?php

namespace Packstub\SessionReplay\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Packstub\SessionReplay\Models\AssistRequest;

/**
 * An agent took a visitor's request ($request->agent_id). Without packstub/calls this is where an app reaches out
 * through its own channels (chat, email): there is no live part.
 */
class AssistClaimed
{
    use Dispatchable;

    public function __construct(public AssistRequest $request) {}
}
