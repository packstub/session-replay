<?php

namespace Packstub\SessionReplay\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Packstub\SessionReplay\Models\ReplayMarker;
use Packstub\SessionReplay\Models\ReplaySession;

/**
 * The person reported a problem from the page (SessionReplay.send()): the batch with the report marker was stored.
 * Once per report; $session->url() is where to watch it.
 */
class ReplayReported
{
    use Dispatchable;

    public function __construct(public ReplaySession $session, public ReplayMarker $marker) {}
}
