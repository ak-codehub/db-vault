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
     * Individual queries executed under a db_session, bound by the CloudWatch
     * ingest job from the RDS MariaDB Audit Plugin stream.
     */
    public function up(): void
    {
        Schema::create('vault_audit_queries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('db_session_id')->constrained('vault_db_sessions')->cascadeOnDelete();
            $table->text('statement');
            $table->string('source_ip')->nullable();
            $table->timestamp('executed_at');
            $table->timestamps();

            $table->index(['db_session_id', 'executed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_audit_queries');
    }
};
