<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What happened during a recording, indexed so a list can filter without
 * opening a chunk: error | console | request | navigation | vital |
 * rage-click | custom.
 */
return new class extends Migration
{
    public function __construct()
    {
        $this->connection = config('session-replay.storage.connection');
    }

    public function up(): void
    {
        Schema::connection($this->connection)->create('replay_markers', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('replay_session_id')->constrained('replay_sessions')->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('label', 500);
            $table->json('payload')->nullable();
            $table->unsignedBigInteger('at_ms'); // epoch milliseconds
            $table->timestamp('created_at')->nullable();

            $table->index(['replay_session_id', 'at_ms']);
            $table->index(['replay_session_id', 'type']); // "reported" (a report marker exists) per row of a list, without reading every marker of the recording
            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('replay_markers');
    }
};
