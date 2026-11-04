<?php

namespace Packstub\SessionReplay\Models;

/**
 * A workspace that sent an asset (a stylesheet or a shared snapshot) itself,
 * the content verified against the hash. Only such a workspace's recordings
 * may point at the asset: a hash alone, learned elsewhere, opens nothing.
 *
 * @property string $hash
 * @property string $tenant_key
 */
class ReplayAssetOwner extends ReplayModel
{
    public const UPDATED_AT = null;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $table = 'replay_asset_owners';

    /** The column's value for a workspace (its morph class and key), or for none. */
    public static function tenantKey(?string $tenantType, ?string $tenantId): string
    {
        return hash('sha256', ($tenantType ?? '').'|'.($tenantId ?? ''));
    }

    /** Records that the workspace sent these assets; one row per hash and workspace. */
    public static function own(string $tenantKey, array $hashes): void
    {
        if ($hashes === []) {
            return;
        }

        static::query()->insertOrIgnore(array_map(fn (string $hash): array => [
            'hash' => $hash,
            'tenant_key' => $tenantKey,
            'created_at' => now(),
        ], $hashes));
    }

    /**
     * Of the given hashes, the ones the workspace sent itself.
     *
     * @param  array<int, string>  $hashes
     * @return array<int, string>
     */
    public static function ownedBy(string $tenantKey, array $hashes): array
    {
        if ($hashes === []) {
            return [];
        }

        return static::query()->where('tenant_key', $tenantKey)->whereIn('hash', $hashes)->pluck('hash')->all();
    }
}
