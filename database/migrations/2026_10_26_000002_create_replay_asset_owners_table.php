<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which workspaces have sent a stylesheet or a shared snapshot themselves,
 * content verified against the hash: a recording may point at an asset only
 * when its workspace is one of them. The files and rows stay one per hash.
 */
return new class extends Migration
{
    public function __construct()
    {
        $this->connection = config('session-replay.storage.connection');
    }

    public function up(): void
    {
        Schema::connection($this->connection)->create('replay_asset_owners', function (Blueprint $table) {
            $table->char('hash', 64);
            $table->char('tenant_key', 64); // ReplayAssetOwner::tenantKey(): the workspace, or "none"
            $table->timestamp('created_at')->nullable();

            $table->primary(['hash', 'tenant_key']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('replay_asset_owners');
    }
};
