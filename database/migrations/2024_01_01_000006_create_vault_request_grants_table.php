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
     * The granular grant matrix for an access request: one row per
     * (table, [column], privilege). `privilege` must be a member of
     * config('dbvault.allowed_privileges'); DROP/TRIGGER are never permitted
     * (enforced in DbVault\Services\ProvisionerService).
     */
    public function up(): void
    {
        Schema::create('vault_request_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('access_request_id')->constrained('vault_access_requests')->cascadeOnDelete();
            $table->string('table_name');
            $table->string('column_name')->nullable();
            $table->string('privilege');
            $table->timestamps();

            $table->index(['access_request_id', 'table_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_request_grants');
    }
};
