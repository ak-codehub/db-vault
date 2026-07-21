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
     * Pivot for the User <-> Role many-to-many relationship.
     */
    public function up(): void
    {
        Schema::create('vault_role_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('vault_users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('vault_roles')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_role_user');
    }
};
