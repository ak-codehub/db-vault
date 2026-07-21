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
     * The single approve/reject decision recorded against an access request.
     */
    public function up(): void
    {
        Schema::create('vault_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('access_request_id')->constrained('vault_access_requests')->cascadeOnDelete();
            $table->foreignId('approver_id')->constrained('vault_users')->cascadeOnDelete();
            $table->enum('decision', ['approve', 'reject']);
            $table->text('note')->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();

            $table->unique('access_request_id');
            $table->index('approver_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_approvals');
    }
};
