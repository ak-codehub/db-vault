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
     * One-time signon tokens used to launch phpMyAdmin against a db_session
     * without ever showing the developer the underlying MySQL credential.
     * Only the hash is stored.
     */
    public function up(): void
    {
        Schema::create('vault_signon_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('db_session_id')->constrained('vault_db_sessions')->cascadeOnDelete();
            $table->string('token_hash')->unique();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['db_session_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_signon_tokens');
    }
};
