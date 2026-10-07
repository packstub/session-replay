<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** replay_assets.kind for installs from before shared snapshots; a fresh install has it from create_replay_assets_table. */
return new class extends Migration
{
    public function __construct()
    {
        $this->connection = config('session-replay.storage.connection');
    }

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasColumn('replay_assets', 'kind')) {
            return;
        }

        Schema::connection($this->connection)->table('replay_assets', function (Blueprint $table) {
            $table->string('kind', 16)->default('stylesheet')->after('hash');
        });
    }

    public function down(): void
    {
        // On a fresh install the column belongs to create_replay_assets_table; nothing to undo here.
    }
};
