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
     * Enrolled client-certificate devices used for the mTLS leg. Nginx
     * terminates the handshake and forwards the verified DN/fingerprint;
     * TrustClientCertificate middleware matches against these rows.
     */
    public function up(): void
    {
        Schema::create('vault_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('vault_users')->cascadeOnDelete();
            $table->string('cert_fingerprint')->unique();
            $table->string('cert_dn');
            $table->string('label')->nullable();
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_devices');
    }
};
