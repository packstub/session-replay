<?php

namespace Packstub\SessionReplay\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Packstub\SessionReplay\Models\AssistRequest;

/**
 * The request is finished, whichever way: $request->status is cancelled, declined, expired, withdrawn or ended, and
 * $request->end_reason says why. $request->grants is the log of what the visitor allowed, and when.
 */
class AssistEnded
{
    use Dispatchable;

    public function __construct(public AssistRequest $request) {}
}
