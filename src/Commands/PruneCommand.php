<?php

namespace Packstub\SessionReplay\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Packstub\SessionReplay\Models\ReplayAsset;
use Packstub\SessionReplay\Models\ReplaySession;
use Packstub\SessionReplay\Models\ReplaySessionAsset;
use Packstub\SessionReplay\Support\ReplayStorage;

class PruneCommand extends Command
{
    protected $signature = 'session-replay:prune {--days= : Keep this many days instead of retention.days}';

    protected $description = 'Delete recordings older than the retention window (pinned ones stay) and the stylesheets and shared snapshots nothing references any more';

    public function handle(ReplayStorage $storage): int
    {
        $days = (int) ($this->option('days') ?? config('session-replay.retention.days', 30));

        if ($days < 1) {
            $this->components->error('The retention window must be at least one day.');

            return self::FAILURE;
        }

        $sessions = 0;

        // One by one, so each recording's files go with it (the model's deleting hook).
        ReplaySession::query()->prunable($days)->chunkById(200, function ($batch) use (&$sessions): void {
            foreach ($batch as $session) {
                $session->delete();
                $sessions++;
            }
        });

        // A stylesheet goes when it was last referenced before the window and no recording that is left points at it
        // (deleteAssets). Recordings from before the references were stored point at nothing, and a batch moved a
        // stylesheet's last-seen date at most once a day: a pinned one of those keeps every stylesheet last seen since
        // it started, as before; the others are inside the window, where the date alone keeps theirs.
        $oldestUnreferenced = ReplaySession::query()
            ->where('pinned', true)
            ->whereNotExists(fn ($references) => $references
                ->from((new ReplaySessionAsset)->getTable())
                ->whereColumn('replay_session_assets.replay_session_id', 'replay_sessions.id'))
            ->min('started_at');

        $assets = $this->deleteAssets($storage, ReplayAsset::query()
            ->where('kind', ReplayAsset::STYLESHEET)
            ->where('last_seen_at', '<', now()->subDays($days))
            ->when($oldestUnreferenced !== null, fn ($query) => $query->where('last_seen_at', '<', Carbon::parse($oldestUnreferenced)->subDays(2))));

        // A shared snapshot goes when no recording that is left points at it. The day of grace covers the moment
        // between its upload and the batch that points at it; sending it again moves the date once it is an hour old,
        // so that moment is never shorter than 23 hours. One that was also sent as a stylesheet is a stylesheet now
        // and goes by the stylesheets' rule.
        $snapshots = $this->deleteAssets($storage, ReplayAsset::query()
            ->where('kind', ReplayAsset::SNAPSHOT)
            ->where(fn ($query) => $query->whereNull('last_seen_at')->orWhere('last_seen_at', '<', now()->subDay())));

        $this->components->info("Pruned {$sessions} recordings and {$assets} stylesheets older than {$days} days, and {$snapshots} shared snapshots no recording points at.");

        return self::SUCCESS;
    }

    /** Deletes the files and rows of the assets the query finds that no recording points at; returns how many. */
    protected function deleteAssets(ReplayStorage $storage, Builder $query): int
    {
        $deleted = 0;

        $query
            ->whereNotExists(fn ($references) => $references
                ->from((new ReplaySessionAsset)->getTable())
                ->whereColumn('replay_session_assets.hash', 'replay_assets.hash'))
            ->chunkById(200, function ($batch) use (&$deleted, $storage): void {
                foreach ($batch as $asset) {
                    $storage->delete($asset->path);
                    $asset->delete();
                    $deleted++;
                }
            });

        return $deleted;
    }
}
