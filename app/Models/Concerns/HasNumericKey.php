<?php

namespace App\Models\Concerns;

/**
 * Keys are BIGINT identity columns (database/sql/schema.sql). They are kept as
 * strings in PHP, like every other id the app reads (config/database.php
 * fetches values as strings), so ids from models and from query rows compare
 * equal with ===.
 */
trait HasNumericKey
{
    public function getKeyType(): string
    {
        return 'string';
    }

    public function getIncrementing(): bool
    {
        return true;
    }
}
