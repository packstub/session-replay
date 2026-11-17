<?php

namespace Packstub\SessionReplay\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Packstub\SessionReplay\Models\AssistRequest;

/** The assist is live: the visitor's page acknowledged the claim, or accepted the proposal, and the call exists ($request->call_id). */
class AssistStarted
{
    use Dispatchable;

    public function __construct(public AssistRequest $request) {}
}
