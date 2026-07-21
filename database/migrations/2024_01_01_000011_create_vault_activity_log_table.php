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
     * Append-only activity/audit trail for actions taken within the vault
     * itself (logins, requests, approvals, provisioning, revocations, signon
     * launches). Distinct from vault_audit_queries, which records SQL run
     * against the brokered target database.
     */
    public function up(): void
    {
        Schema::create('vault_activity_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('vault_users')->nullOnDelete();
            $table->string('action');
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('device_dn')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['subject_type', 'subject_id']);
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_activity_log');
    }
};
