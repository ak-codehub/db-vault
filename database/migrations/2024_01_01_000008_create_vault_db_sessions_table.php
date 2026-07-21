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
     * A provisioned, per-request temporary MySQL user/session. Created by the
     * Provisioner on approval and dropped at expiry, logout, or revoke.
     */
    public function up(): void
    {
        Schema::create('vault_db_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('access_request_id')->constrained('vault_access_requests')->cascadeOnDelete();
            $table->string('mysql_username')->unique();
            $table->string('status')->default('pending');
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('dropped_at')->nullable();
            $table->unsignedSmallInteger('max_connections');
            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_db_sessions');
    }
};
