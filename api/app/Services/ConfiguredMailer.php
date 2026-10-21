<?php

namespace App\Services;

use App\Models\EmailConfiguration;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class ConfiguredMailer
{
    /** Apply saved settings at send time so long-running queue workers use the latest account. */
    public function send(string $recipient, object $mailable, bool $allowUnverified = false): void
    {
        $configuration = EmailConfiguration::query()->first();
        if (! $configuration) {
            if (app()->environment('testing')) {
                Mail::to($recipient)->send($mailable);
                return;
            }
            throw new RuntimeException('Configure and verify an SMTP sender account in System Settings before sending email.');
        }
        if ($configuration && ! $configuration->is_connected && ! $allowUnverified) {
            throw new RuntimeException('The configured email account has not been verified. Go to System Settings → Email Settings and send a test email to verify the account.');
        }

        try {
            $rawPassword = $configuration->password;
        } catch (\Throwable $e) {
            throw new RuntimeException('The stored email password could not be decrypted (encryption key mismatch). Please re-enter and save your email password in Settings.', previous: $e);
        }

        $isGoogle = $configuration->provider === 'gmail'
            || str_contains(strtolower((string) $configuration->host), 'gmail')
            || str_contains(strtolower((string) $configuration->host), 'google');

        $cleanPassword = (string) $rawPassword;
        if ($isGoogle) {
            // Google App Passwords must have all whitespace and grouping spaces removed
            $cleanPassword = preg_replace('/\s+/', '', $cleanPassword);
        } else {
            $cleanPassword = trim($cleanPassword);
        }

        $username = trim((string) $configuration->username);
        $senderEmail = trim((string) $configuration->sender_email);
        if ($isGoogle && ! str_contains($username, '@') && $senderEmail !== '') {
            $username = $senderEmail;
        }

        $port = (int) $configuration->port;
        $encryption = strtolower(trim((string) $configuration->encryption));
        $isSsl = ($encryption === 'ssl' || $port === 465);
        $scheme = $isSsl ? 'smtps' : 'smtp';
        $autoTls = ($encryption !== 'none');

        $localDomain = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (! $localDomain || $localDomain === 'localhost' || filter_var($localDomain, FILTER_VALIDATE_IP)) {
            $localDomain = null;
        }

        config([
            'mail.mailers.masterexam' => [
                'transport' => 'smtp',
                'scheme' => $scheme,
                'host' => trim((string) $configuration->host),
                'port' => $port,
                'username' => $username,
                'password' => $cleanPassword,
                'timeout' => 25,
                'auto_tls' => $autoTls,
                'local_domain' => $localDomain,
            ],
            'mail.from.address' => $senderEmail,
            'mail.from.name' => trim((string) $configuration->sender_name),
        ]);

        Mail::purge('masterexam');
        Mail::mailer('masterexam')->to($recipient)->send($mailable);
        $configuration->forceFill(['last_success_at' => now()])->save();
    }
}
