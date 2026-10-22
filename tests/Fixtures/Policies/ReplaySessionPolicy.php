<?php

namespace Packstub\SessionReplay\Tests\Fixtures\Policies;

use Packstub\SessionReplay\Models\ReplaySession;
use Packstub\SessionReplay\Tests\Fixtures\Models\User;

/** What Filament Shield generates, in short: the list for staff, a recording for staff unless it is pinned. */
class ReplaySessionPolicy
{
    public function viewAny(User $user): bool
    {
        return (bool) $user->is_staff;
    }

    public function view(User $user, ReplaySession $session): bool
    {
        return $user->is_staff && ! $session->pinned;
    }
}
