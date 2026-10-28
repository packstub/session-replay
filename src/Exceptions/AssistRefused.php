<?php

namespace Packstub\SessionReplay\Exceptions;

use RuntimeException;

/**
 * An assist action that may not happen: `status` is the HTTP status the routes answer with, `reason` a word a client
 * can tell the refusals apart by (`taken`, `exists`, `not_allowed`, `guard_mismatch`, `too_many`, `full`, `not_live`,
 * `no_calls`, `state`).
 */
class AssistRefused extends RuntimeException
{
    /** @param  array<string, mixed>  $extra  more for the JSON answer (the holder's name on a 409) */
    public function __construct(public int $status, string $message, public string $reason, public array $extra = [])
    {
        parent::__construct($message);
    }
}
