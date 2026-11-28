<?php

namespace Packstub\SessionReplay\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Packstub\SessionReplay\Models\Relations\MorphToOwnConnection;

/** The replay tables follow session-replay.storage.connection, so they stay central in a database-per-tenant app. */
abstract class ReplayModel extends Model
{
    protected $guarded = [];

    public function getConnectionName(): ?string
    {
        return config('session-replay.storage.connection') ?: parent::getConnectionName();
    }

    /** The user, tenant, impersonator and agent relations: a model without a connection of its own loads on the default one. */
    protected function morphInstanceTo($target, $name, $type, $id, $ownerKey)
    {
        $class = static::getActualClassNameForMorph($target);
        $instance = new $class;

        return $this->newMorphTo($instance->newQuery(), $this, $id, $ownerKey ?? $instance->getKeyName(), $type, $name);
    }

    protected function newMorphTo(Builder $query, Model $parent, $foreignKey, $ownerKey, $type, $relation)
    {
        return new MorphToOwnConnection($query, $parent, $foreignKey, $ownerKey, $type, $relation);
    }
}
