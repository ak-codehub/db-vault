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
     * The vault's own operators (developers, approvers, admins) — entirely
     * separate from the host application's users table. Local email/password
     * auth plus opt-in TOTP or email-OTP second factor; gated by mTLS at the
     * middleware layer. There is no external SSO and no self-registration.
     */
    public function up(): void
    {
        Schema::create('vault_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->string('email_otp_code')->nullable();
            $table->timestamp('email_otp_expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_users');
    }
};
