<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Writes one row to audit_log: who did what to which record, with the record's
 * state before and after. Never pass secrets (password hashes, tokens) in the
 * snapshots.
 */
final class Audit
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public static function record(string $entity, string $entityId, string $action, ?array $before, ?array $after, ?string $userId = null): void
    {
        DB::table('audit_log')->insert([
            'entity' => $entity,
            'entity_id' => $entityId,
            'action' => $action,
            'user_id' => $userId ?? Auth::id(),
            'before_data' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
            'after_data' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
        ]);
    }
}
