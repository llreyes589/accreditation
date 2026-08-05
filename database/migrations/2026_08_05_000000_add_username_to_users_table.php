<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddUsernameToUsersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->unique()->after('name');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            // SQLite < 3.35 has no ALTER TABLE ... DROP COLUMN, so rebuild
            // the table without the username column (preserving the primary
            // key and the unique index on email).
            DB::statement('PRAGMA foreign_keys = OFF');
            DB::statement(
                'CREATE TABLE __users_tmp AS SELECT '
                . 'id, name, email, email_verified_at, password, remember_token, '
                . 'created_at, updated_at, status, approved_at, approved_by, rejection_reason '
                . 'FROM users'
            );
            DB::statement('DROP TABLE users');
            DB::statement('ALTER TABLE __users_tmp RENAME TO users');
            DB::statement('CREATE UNIQUE INDEX users_email_unique ON users (email)');
            DB::statement('PRAGMA foreign_keys = ON');
        } else {
            // MySQL / PostgreSQL: drop the unique index then the column.
            Schema::table('users', function (Blueprint $table) {
                $table->dropUnique(['username']);
                $table->dropColumn('username');
            });
        }
    }
}
