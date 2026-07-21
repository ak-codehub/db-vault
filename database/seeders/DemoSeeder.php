<?php

declare(strict_types=1);

namespace DbVault\Database\Seeders;

use DbVault\Enums\Privilege;
use DbVault\Enums\RequestStatus;
use DbVault\Enums\SessionStatus;
use DbVault\Models\AccessRequest;
use DbVault\Models\Approval;
use DbVault\Models\DbSession;
use DbVault\Models\RequestGrant;
use DbVault\Models\Role;
use DbVault\Models\User;
use Illuminate\Database\Seeder;

/**
 * Populates a database with a handful of users across every role and sample
 * access requests spanning each lifecycle state, so the SPA renders realistic
 * data during local development or a demo. Never run automatically outside the
 * `local` environment (see the host app's DatabaseSeeder guard); run
 * explicitly elsewhere with `php artisan db:seed --class="DbVault\Database\Seeders\DemoSeeder"`.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        (new RoleSeeder())->run();

        $arun = $this->user('Arun Kumar', 'arun@dbvault.test', 'developer');
        $priya = $this->user('Priya Nair', 'priya@dbvault.test', 'developer');
        $meena = $this->user('Meena Rao', 'meena@dbvault.test', 'developer');
        $approver = $this->user('Sanjay Approver', 'approver@dbvault.test', 'approver');
        $this->user('Vault Admin', 'admin@dbvault.test', 'admin');

        // Pending — sitting in the approvers' queue.
        $pending = $this->request($priya, RequestStatus::PendingApproval, 'Hotfix for duplicated line items in checkout', [
            ['orders', [Privilege::Select, Privilege::Update]],
            ['order_items', [Privilege::Update]],
        ]);
        $pending->update(['requested_at' => now()->subMinutes(20)]);

        $this->request($meena, RequestStatus::PendingApproval, 'Investigate payment reconciliation discrepancy', [
            ['payments', [Privilege::Select]],
        ])->update(['requested_at' => now()->subMinutes(5)]);

        // Active — approved and provisioned with a live session.
        $active = $this->request($arun, RequestStatus::Active, 'Weekly analytics export', [
            ['reports', [Privilege::Select]],
        ]);
        $active->update(['requested_at' => now()->subHours(2)]);
        Approval::create([
            'access_request_id' => $active->id,
            'approver_id' => $approver->id,
            'decision' => 'approve',
            'note' => 'Read-only, time-boxed. Approved.',
            'decided_at' => now()->subHour(),
        ]);
        DbSession::create([
            'access_request_id' => $active->id,
            'mysql_username' => 'dbv_arun_req'.$active->id,
            'status' => SessionStatus::Active,
            'provisioned_at' => now()->subHour(),
            'expires_at' => now()->addMinutes(30),
            'max_connections' => (int) config('dbvault.max_user_connections', 3),
        ]);

        // Rejected.
        $rejected = $this->request($priya, RequestStatus::Rejected, 'Bulk update customer emails', [
            ['customers', [Privilege::Update]],
        ]);
        Approval::create([
            'access_request_id' => $rejected->id,
            'approver_id' => $approver->id,
            'decision' => 'reject',
            'note' => 'Too broad — scope to the affected rows and resubmit.',
            'decided_at' => now()->subMinutes(90),
        ]);
    }

    private function user(string $name, string $email, string $role): User
    {
        $user = User::firstOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => 'password', 'is_active' => true],
        );

        $roleId = Role::where('name', $role)->value('id');
        $user->roles()->syncWithoutDetaching([$roleId]);

        return $user;
    }

    /**
     * @param  list<array{0: string, 1: list<Privilege>}>  $grants
     */
    private function request(User $user, RequestStatus $status, string $reason, array $grants): AccessRequest
    {
        $request = AccessRequest::create([
            'user_id' => $user->id,
            'target_database' => config('dbvault.target_database', 'appdb'),
            'duration_minutes' => 60,
            'reason' => $reason,
            'status' => $status,
            'requested_at' => $status === RequestStatus::Draft ? null : now(),
        ]);

        foreach ($grants as [$table, $privileges]) {
            foreach ($privileges as $privilege) {
                RequestGrant::create([
                    'access_request_id' => $request->id,
                    'table_name' => $table,
                    'column_name' => null,
                    'privilege' => $privilege->value,
                ]);
            }
        }

        return $request;
    }
}
