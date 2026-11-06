<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, string> code => description */
    private const PERMISSIONS = [
        'driver.view' => 'View drivers',
        'driver.create' => 'Add drivers',
        'driver.edit' => 'Edit drivers',
        'driver.delete' => 'Delete drivers',
    ];

    /** Roles that get all four at first (ADMIN gets them too). */
    private const ROLES = ['ADMIN', 'MANAGER', 'DATA_ENTRY'];

    /**
     * Settings › Drivers: a company-wide list of drivers with their own
     * permissions. A Delivery note is dispatched with one of them and keeps a
     * copy of their details; a driver on any delivery note can't be deleted.
     *
     * Same as schema.sql; a database created from it already has all of this.
     */
    public function up(): void
    {
        if (Schema::hasTable('driver')) {
            Permissions::flush();

            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TABLE driver (
                id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                name        VARCHAR(100) NOT NULL,
                licence_no  VARCHAR(30) NOT NULL UNIQUE,
                national_id VARCHAR(30) NOT NULL UNIQUE,
                phone       VARCHAR(30) NOT NULL,
                truck_no    VARCHAR(30) NOT NULL,
                created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at  TIMESTAMPTZ NOT NULL DEFAULT now()
            );

            ALTER TABLE driver ENABLE ROW LEVEL SECURITY;

            ALTER TABLE stock_transaction
                ADD COLUMN driver_id          BIGINT REFERENCES driver(id),
                ADD COLUMN driver_licence_no  VARCHAR(30),
                ADD COLUMN driver_national_id VARCHAR(30);

            CREATE INDEX idx_stock_transaction_driver ON stock_transaction (driver_id) WHERE driver_id IS NOT NULL;
            SQL);

        $roles = DB::table('role')->whereIn('code', self::ROLES)->pluck('id');

        foreach (self::PERMISSIONS as $code => $description) {
            $permissionId = DB::table('permission')->insertGetId(['code' => $code, 'description' => $description, 'module' => 'DRIVER'], 'id');

            DB::table('role_permission')->insertOrIgnore($roles->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $permissionId])->values()->all());
        }

        Permissions::flush();
    }

    public function down(): void
    {
        DB::table('permission')->whereIn('code', array_keys(self::PERMISSIONS))->delete();

        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS idx_stock_transaction_driver;
            ALTER TABLE stock_transaction
                DROP COLUMN IF EXISTS driver_id,
                DROP COLUMN IF EXISTS driver_licence_no,
                DROP COLUMN IF EXISTS driver_national_id;
            DROP TABLE IF EXISTS driver;
            SQL);

        Permissions::flush();
    }
};
