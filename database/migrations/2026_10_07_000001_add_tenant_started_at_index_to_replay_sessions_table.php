<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The index a workspace's list sorts by, for installs from before it; a fresh install has it from create_replay_sessions_table. */
return new class extends Migration
{
    public function __construct()
    {
        $this->connection = config('session-replay.storage.connection');
    }

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasIndex('replay_sessions', ['tenant_type', 'tenant_id', 'started_at'])) {
            return;
        }

        Schema::connection($this->connection)->table('replay_sessions', function (Blueprint $table) {
            $table->index(['tenant_type', 'tenant_id', 'started_at']);
        });
    }

    public function down(): void
    {
        // On a fresh install the index belongs to create_replay_sessions_table; nothing to undo here.
    }
};
