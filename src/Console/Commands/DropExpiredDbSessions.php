<?php

declare(strict_types=1);

namespace DbVault\Console\Commands;

use DbVault\Enums\SessionStatus;
use DbVault\Models\ActivityLog;
use DbVault\Models\DbSession;
use DbVault\Services\ProvisionerService;
use Illuminate\Console\Command;

/**
 * Scheduled cleanup: drops the temporary MySQL user for every db_session that
 * has passed its expires_at but is still Pending/Active. Explicit revokes and
 * logout-triggered drops are handled synchronously elsewhere
 * (DbVault\Http\Controllers\Api\DbSessionController::revoke()); this command
 * is the safety net for sessions nobody explicitly closed out.
 *
 * Schedule it from the host app's routes/console.php, e.g.:
 *   Schedule::command('dbvault:drop-expired-sessions')->everyFiveMinutes();
 */
class DropExpiredDbSessions extends Command
{
    protected $signature = 'dbvault:drop-expired-sessions';

    protected $description = 'Drop temporary MySQL users for db_sessions past their expiry.';

    public function handle(ProvisionerService $provisioner): int
    {
        $expired = DbSession::query()
            ->whereIn('status', SessionStatus::live())
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($expired as $dbSession) {
            $provisioner->dropSession($dbSession);

            $dbSession->update(['status' => SessionStatus::Expired]);

            ActivityLog::create([
                'actor_id' => null,
                'action' => 'db_session.expired_and_dropped',
                'subject_type' => $dbSession->getMorphClass(),
                'subject_id' => $dbSession->getKey(),
            ]);
        }

        $this->info(sprintf('Dropped %d expired db session(s).', $expired->count()));

        return self::SUCCESS;
    }
}
