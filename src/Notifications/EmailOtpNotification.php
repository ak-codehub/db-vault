<?php

declare(strict_types=1);

namespace DbVault\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The fallback 2FA channel: a numeric one-time code emailed to the vault
 * user, issued by DbVault\Services\TwoFactorService::issueEmailOtp().
 */
class EmailOtpNotification extends Notification
{
    use Queueable;

    public function __construct(protected string $code, protected int $ttlMinutes)
    {
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your '.config('dbvault.two_factor.issuer', 'DB Vault').' sign-in code')
            ->line("Your one-time sign-in code is: {$this->code}")
            ->line("This code expires in {$this->ttlMinutes} minutes.")
            ->line('If you did not attempt to sign in, you can safely ignore this email.');
    }
}
