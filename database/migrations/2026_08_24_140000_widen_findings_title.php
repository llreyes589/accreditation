<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Findings can be raised from non-compliant checklist items whose "title" is the
 * full clause text (often a full paragraph). The original VARCHAR(255) truncates
 * that and throws SQLSTATE 22001 on inspection submission. Widen to TEXT.
 *
 * Uses raw ALTER (no doctrine/dbal dependency) for portability on PHP 8.4 / MySQL.
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement('ALTER TABLE findings MODIFY title TEXT NOT NULL');
    }

    public function down()
    {
        // Reverts to the original VARCHAR(255); will fail if a longer title exists (expected on revert).
        DB::statement('ALTER TABLE findings MODIFY title VARCHAR(255) NOT NULL');
    }
};
