<?php

use App\Support\CompanyTime;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One company-wide time zone (Settings › General) instead of one per
     * warehouse: app_setting 'timezone', starting on the time zone most
     * warehouses had, and warehouse.timezone goes. Same as schema.sql; a
     * database created from it already has it.
     */
    public function up(): void
    {
        if (Schema::hasTable('app_setting')) {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE TABLE app_setting (
                key         VARCHAR(50) PRIMARY KEY,
                value       TEXT NOT NULL,
                updated_by  BIGINT REFERENCES app_user(id),
                updated_at  TIMESTAMPTZ NOT NULL DEFAULT now()
            )
        SQL);
        DB::statement('ALTER TABLE app_setting ENABLE ROW LEVEL SECURITY');

        $zone = Schema::hasColumn('warehouse', 'timezone')
            ? DB::table('warehouse')->select('timezone')->groupBy('timezone')->orderByRaw('count(*) DESC, timezone')->value('timezone')
            : null;

        DB::table('app_setting')->insert([
            'key' => 'timezone',
            'value' => is_string($zone) && CompanyTime::valid($zone) ? $zone : CompanyTime::DEFAULT,
        ]);

        if (Schema::hasColumn('warehouse', 'timezone')) {
            DB::statement('ALTER TABLE warehouse DROP COLUMN timezone');
        }

        CompanyTime::flush();
    }

    public function down(): void
    {
        $zone = DB::table('app_setting')->where('key', 'timezone')->value('value') ?? CompanyTime::DEFAULT;

        DB::statement("ALTER TABLE warehouse ADD COLUMN timezone VARCHAR(50) NOT NULL DEFAULT 'Asia/Beirut'");
        DB::table('warehouse')->update(['timezone' => $zone]);
        DB::statement('DROP TABLE app_setting');

        CompanyTime::flush();
    }
};
