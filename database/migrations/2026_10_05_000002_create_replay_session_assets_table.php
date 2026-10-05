<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which shared snapshots a recording points at: what the viewer checks before
 * it serves one, and what keeps one from being pruned while a recording that
 * is left still needs it.
 */
return new class extends Migration
{
    public function __construct()
    {
        $this->connection = config('session-replay.storage.connection');
    }

    public function up(): void
    {
        Schema::connection($this->connection)->create('replay_session_assets', function (Blueprint $table) {
            $table->foreignUuid('replay_session_id')->constrained('replay_sessions')->cascadeOnDelete();
            $table->char('hash', 64)->index();
            $table->timestamp('created_at')->nullable();

            $table->primary(['replay_session_id', 'hash']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('replay_session_assets');
    }
};
