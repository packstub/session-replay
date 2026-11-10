<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assist: a visitor asks for help, or an agent offers it from a live session.
 * One row per request, in both directions (opened_by). The visitor proves the
 * tab with a per-request secret (stored as its SHA-256); the agent is the
 * viewer guard's user. See AssistRequest for the statuses.
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
        // SQL Server lets a unique index hold one NULL only: there it is filtered to the rows that have a key.
        $sqlServer = $schema->getConnection()->getDriverName() === 'sqlsrv';

        $schema->create('replay_assist_requests', function (Blueprint $table) use ($sqlServer) {
            $table->uuid('id')->primary();
            $table->char('secret_hash', 64)->nullable(); // null until issued (an agent's proposal, before the page answers it)
            // The recording the tab is making; null for a tab that records nothing. Moves to the tab's next recording
            // (re-bind); goes with the recording it points at.
            $table->foreignUuid('replay_session_id')->nullable()->constrained('replay_sessions')->cascadeOnDelete();
            $table->string('status', 16)->default('open');
            $table->string('opened_by', 8); // visitor | agent
            $table->string('user_type')->nullable();
            $table->string('user_id')->nullable();
            $table->string('tenant_type')->nullable();
            $table->string('tenant_id')->nullable();
            // A keyed hash of who the token named (person or pseudonym, impersonator), salted with the request's id; null
            // for a visitor nobody signed in as. A token that names someone else ends the request.
            $table->char('identity', 64)->nullable();
            $table->string('nonce', 64)->nullable(); // the token's page nonce, for a tab without a recording
            // Set while the request is not finished (the recording's id, or for a tab without one "tab:" and a hash of the
            // token's nonce and the tab's own id, so pages served from a full-page cache do not share it): unique, so a
            // recording (or a tab without one) never has two requests going at once, however the requests race.
            $table->string('lock_key', 80)->nullable();

            if (! $sqlServer) {
                $table->unique('lock_key');
            }

            // The agent as the request they claimed or proposed from saw them: the viewer guard's key, the morph class of
            // that guard's user, the guard's name and Calls' scope there. The call is created later in the visitor's
            // request, where no panel is active: the agent is loaded and rung from these, never from the default guard.
            $table->string('agent_id', 64)->nullable();
            $table->string('agent_type')->nullable();
            $table->string('agent_guard', 64)->nullable();
            $table->string('agent_scope', 64)->nullable();
            $table->string('agent_name')->nullable();
            $table->string('agent_avatar', 2000)->nullable();
            $table->unsignedBigInteger('call_id')->nullable()->index();
            $table->string('message', 2000)->nullable(); // 500 characters, rendered as text
            $table->string('page_url', 2000)->nullable(); // redacted like every URL the recorder sends
            $table->json('grants')->nullable();
            $table->timestamp('visitor_seen_at')->nullable();
            $table->timestamp('agent_seen_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 32)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['tenant_type', 'tenant_id', 'status']);
        });

        if ($sqlServer) {
            $connection = $schema->getConnection();
            $index = $connection->getTablePrefix().'replay_assist_requests_lock_key_unique';

            $connection->statement('create unique index '.$index.' on '.$connection->getQueryGrammar()->wrapTable('replay_assist_requests').' (lock_key) where lock_key is not null');
        }
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('replay_assist_requests');
    }
};
