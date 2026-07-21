<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function __construct()
    {
        $this->connection = config('dbvault.connection');
    }

    /**
     * Encrypted, short-lived temp-user password. Set at provisioning time and
     * cleared once handed to the phpMyAdmin signon flow (or on drop). Never
     * surfaced to the developer directly.
     */
    public function up(): void
    {
        Schema::table('vault_db_sessions', function (Blueprint $table) {
            $table->text('secret')->nullable()->after('mysql_username');
        });
    }

    public function down(): void
    {
        Schema::table('vault_db_sessions', function (Blueprint $table) {
            $table->dropColumn('secret');
        });
    }
};
