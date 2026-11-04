<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two indexes the lists need at scale, for installs from before them; a fresh install has them from the create_*
 * migrations. replay_sessions(user_id): one person's recordings when the type is not given (the viewer's ?user=, a
 * key typed into the Filament filter or search), which only the (user_type, user_id) index could not serve.
 * replay_markers(replay_session_id, type): the "reported" badge and filter ask per recording whether a report
 * marker exists, and read every marker of it without this.
 */
return new class extends Migration
{
    public function __construct()
    {
        $this->connection = config('session-replay.storage.connection');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasIndex('replay_sessions', ['user_id'])) {
            $schema->table('replay_sessions', function (Blueprint $table) {
                $table->index('user_id');
            });
        }

        if (! $schema->hasIndex('replay_markers', ['replay_session_id', 'type'])) {
            $schema->table('replay_markers', function (Blueprint $table) {
                $table->index(['replay_session_id', 'type']);
            });
        }
    }

    public function down(): void
    {
        // On a fresh install the indexes belong to the create_* migrations; nothing to undo here.
    }
};
