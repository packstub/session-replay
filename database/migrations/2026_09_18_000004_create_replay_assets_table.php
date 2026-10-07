<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stylesheets taken out of the snapshots, and the snapshots of pages that
 * share them (kind "snapshot"), stored once per SHA-256 of their content and
 * shared by every recording that references them.
 */
return new class extends Migration
{
    public function __construct()
    {
        $this->connection = config('session-replay.storage.connection');
    }

    public function up(): void
    {
        Schema::connection($this->connection)->create('replay_assets', function (Blueprint $table) {
            $table->id();
            $table->char('hash', 64)->unique();
            $table->string('kind', 16)->default('stylesheet'); // stylesheet | snapshot
            $table->string('path');
            $table->unsignedInteger('bytes'); // as stored (gzip)
            $table->unsignedInteger('raw_bytes');
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('replay_assets');
    }
};
