<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Issue is now called Delivery note, Draft issue is now Order. Codes (OUT,
     * stock.out.*, draft.out.*) stay; only the stored names change. Same as
     * schema.sql; a database created from it already has them.
     *
     * @var array<string, array{0: string, 1: string}> permission code => [old, new]
     */
    private const PERMISSIONS = [
        'draft.out.create' => ['Create and edit draft issues', 'Create and edit orders'],
        'draft.out.confirm' => ['Confirm draft issues (creates the issues)', 'Confirm orders (creates the delivery notes)'],
        'stock.out.view' => ['View issues', 'View delivery notes'],
        'draft.out.view' => ['View draft issues', 'View orders'],
    ];

    public function up(): void
    {
        if (DB::table('transaction_type')->where('code', 'OUT')->value('name') === 'Delivery note (OUT)') {
            return;
        }

        $this->rename('Delivery note (OUT)', 1);
    }

    public function down(): void
    {
        $this->rename('Issuing (OUT)', 0);
    }

    private function rename(string $typeName, int $to): void
    {
        DB::table('transaction_type')->where('code', 'OUT')->update(['name' => $typeName]);

        foreach (self::PERMISSIONS as $code => $names) {
            DB::table('permission')->where('code', $code)->update(['description' => $names[$to]]);
        }

        Permissions::flush();
    }
};
