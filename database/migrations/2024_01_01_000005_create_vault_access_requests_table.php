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
     * A developer's request for temporary, scoped access to a target
     * database. Lifecycle tracked via `status` (see DbVault\Enums\RequestStatus).
     */
    public function up(): void
    {
        Schema::create('vault_access_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('vault_users')->cascadeOnDelete();
            $table->string('target_database');
            $table->unsignedInteger('duration_minutes');
            $table->text('reason');
            $table->string('status')->default('draft');
            $table->timestamp('requested_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_access_requests');
    }
};
