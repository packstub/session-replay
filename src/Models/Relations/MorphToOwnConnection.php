<?php

namespace Packstub\SessionReplay\Models\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A morphTo whose related model keeps its own connection. Laravel hands a model that declares none the parent's,
 * which is session-replay.storage.connection here: in a database-per-tenant app the users would be looked up in the
 * central database. Without a connection of its own the model stays on the default one, where the app's auth finds it.
 *
 * @internal
 */
class MorphToOwnConnection extends MorphTo
{
    public function createModelByType($type)
    {
        $class = Model::getActualClassNameForMorph($type);

        return new $class;
    }
}
