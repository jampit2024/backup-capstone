<?php

namespace App\Services;

use Illuminate\Support\Str;
use Throwable;

class EmailFailureClassifier
{
    /**
     * @return array{code: string, title: string, message: string}
     */
    public function classify(Throwable|string $failure, ?string $provider = null): array
    {
        $message = $failure instanceof Throwable ? $failure->getMessage() : $failure;
        $normalized = Str::lower($message);
        $isGoogle = $provider === 'gmail'
            || str_contains($normalized, 'gmail')
            || str_contains($normalized, 'smtp.google');

        if ($this->containsAny($normalized, ['mac is invalid', 'payload is invalid', 'decryptexception', 'could not be decrypted'])) {
            return [
                'code' => 'decryption_failed',
                'title' => 'Saved password unreadable',
                'message' => 'The stored email password could not be decrypted (possibly due to an encryption key update). Please re-enter and save your email password in Settings.',
                'help_url' => null,
            ];
        }

        if ($this->containsAny($normalized, [
            'gmail app password must',
            '535',
            '5.7.8',
            'username and password not accepted',
            'authentication failed',
            'unable to authenticate',
            'invalid credentials',
        ])) {
            return [
                'code' => 'authentication_failed',
                'title' => 'Authentication failed',
                'message' => $isGoogle
                    ? 'Google rejected the sending account credentials. Verify that the Gmail address is correct and that you entered a valid 16-character Google App Password (not your regular Gmail password). If necessary, generate a new App Password or verify 2-Step Verification is active.'
                    : 'The email provider rejected the account credentials. Check the username and password, then try again.',
                'help_url' => $isGoogle ? 'https://support.google.com/accounts/answer/185833' : null,
            ];
        }

        if ($this->containsAny($normalized, ['authenticator', 'authentication method', 'auth mechanism', 'xoauth2'])) {
            return [
                'code' => 'unsupported_authentication',
                'title' => 'Connection method not allowed',
                'message' => $isGoogle
                    ? 'This Google account does not allow App Password sign-in. Ask your Google Workspace administrator to permit 2-Step Verification and App Passwords, or use an approved connection method.'
                    : 'The email provider does not allow this sign-in method. Ask the provider which SMTP authentication method is supported.',
                'help_url' => $isGoogle ? 'https://support.google.com/accounts/answer/185833' : null,
            ];
        }

        if ($this->containsAny($normalized, ['timed out', 'timeout', 'operation timed out'])) {
            return [
                'code' => 'connection_timeout',
                'title' => 'Connection timed out',
                'message' => 'The email server did not respond in time. Check the server address, port, firewall, and internet connection, then try again.',
            ];
        }

        if ($this->containsAny($normalized, [
            'connection refused',
            'could not connect',
            'connection could not be established',
            'getaddrinfo',
            'php_network_getaddresses',
            'no route to host',
        ])) {
            return [
                'code' => 'provider_unavailable',
                'title' => 'Email server unavailable',
                'message' => 'MasterExam could not reach the email server. Check the server address and port, or try again later.',
            ];
        }

        if ($this->containsAny($normalized, ['certificate', 'tls', 'ssl', 'crypto'])) {
            return [
                'code' => 'secure_connection_failed',
                'title' => 'Secure connection failed',
                'message' => 'MasterExam could not create a secure connection to the email server. Check the selected security type and port.',
            ];
        }

        if ($this->containsAny($normalized, ['sender address rejected', 'mailbox unavailable', 'from address', 'sender rejected'])) {
            return [
                'code' => 'invalid_sender',
                'title' => 'Sender address rejected',
                'message' => 'The provider did not allow this sender address. Use the same address as the authenticated account or an approved alias.',
            ];
        }

        if ($this->containsAny($normalized, ['daily user sending limit', 'rate limit', 'too many', 'quota', '4.7.0', '4.2.1'])) {
            return [
                'code' => 'provider_limit_reached',
                'title' => 'Sending limit reached',
                'message' => 'The email provider temporarily stopped sending because an account limit was reached. Wait and try again later.',
            ];
        }

        if ($this->containsAny($normalized, ['configure and verify', 'not been verified', 'save an email configuration'])) {
            return [
                'code' => 'configuration_missing',
                'title' => 'Email account not ready',
                'message' => 'Save and test an email account in System Settings before sending messages.',
            ];
        }

        return [
            'code' => 'sending_failed',
            'title' => 'Email could not be sent',
            'message' => 'The email provider did not accept the message. Check the email settings and try again.',
        ];
    }

    /**
     * Remove credentials and email addresses before a diagnostic is written to server logs.
     *
     * @param  list<string|null>  $sensitiveValues
     */
    public function safeDiagnostic(Throwable $exception, array $sensitiveValues = []): string
    {
        $message = $exception->getMessage();

        foreach ($sensitiveValues as $value) {
            if (is_string($value) && $value !== '') {
                $message = str_ireplace($value, '[redacted]', $message);
            }
        }

        $message = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email redacted]', $message) ?? $message;
        $message = preg_replace('/(password|token|secret)\s*[=:]\s*[^\s,;]+/i', '$1=[redacted]', $message) ?? $message;

        return Str::limit($message, 1000, '…');
    }

    private function containsAny(string $message, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}
