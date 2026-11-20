<?php

namespace Packstub\SessionReplay\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;

/**
 * Takes the write lock on rows at the start of a transaction that reads them and then writes.
 *
 * On SQLite, lockForUpdate() does nothing, and a transaction that has read cannot wait for the write lock: when
 * another connection holds it, the first write fails at once with "database is locked", whatever the busy
 * timeout says (the snapshot it read may be stale by then). A transaction whose first statement is a write waits
 * for the lock like any other, so the read after it sees the latest row. On MySQL and Postgres the same UPDATE
 * locks the rows, as lockForUpdate() does.
 *
 * @internal
 */
final class RowLock
{
    /**
     * Rewrite each row's key with itself: no column changes, no timestamps touched.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    public static function take(Builder $query): void
    {
        $key = $query->getModel()->getKeyName();

        $query->toBase()->update([$key => new Expression($query->getQuery()->getGrammar()->wrap($key))]);
    }
}
