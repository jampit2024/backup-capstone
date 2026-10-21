<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\EmailConnectionTestMail;
use App\Models\EmailConfiguration;
use App\Models\EmailNotification;
use App\Models\ExaminationRegistration;
use App\Services\ConfiguredMailer;
use App\Services\EmailFailureClassifier;
use App\Services\ExaminationPasskeyService;
use App\Services\ExaminationResultService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

class EmailConfigurationController extends Controller
{
    public function show(): JsonResponse
    {
        $configuration = EmailConfiguration::query()->first();
        return response()->json(['success' => true, 'data' => $configuration ? $this->safeConfiguration($configuration) : null]);
    }

    public function save(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['required', Rule::in(['smtp', 'gmail'])],
            'sender_email' => ['required', 'email:rfc', 'max:254'],
            'sender_name' => ['required', 'string', 'max:120'],
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'encryption' => ['required', Rule::in(['tls', 'ssl', 'none'])],
            'username' => ['required', 'string', 'max:254'],
            'password' => ['nullable', 'string', 'max:4096'],
        ]);

        $validated['sender_email'] = trim($validated['sender_email']);
        $validated['sender_name'] = trim($validated['sender_name']);
        $validated['host'] = trim($validated['host']);
        $validated['username'] = trim($validated['username']);

        $isGoogle = $validated['provider'] === 'gmail'
            || str_contains(strtolower($validated['host']), 'gmail')
            || str_contains(strtolower($validated['host']), 'google');

        if ($isGoogle) {
            $validated['host'] = 'smtp.gmail.com';
            $port = (int) $validated['port'];
            if (! in_array($port, [465, 587], true)) {
                $validated['port'] = 587;
            }
            if ((int) $validated['port'] === 465) {
                $validated['encryption'] = 'ssl';
            } else {
                $validated['encryption'] = 'tls';
            }
            // Gmail authentication requires the full email address
            $validated['username'] = $validated['sender_email'];
        }

        $configuration = EmailConfiguration::query()->first();
        $rawPassword = $request->input('password');

        if (! $configuration && blank($rawPassword)) {
            return response()->json(['success' => false, 'message' => 'Enter the SMTP password or app password.'], 422);
        }

        unset($validated['password']);
        $validated['is_connected'] = false;
        $validated['verified_at'] = null;
        $validated['updated_by'] = $request->user()?->id;

        $configuration ??= new EmailConfiguration;
        $configuration->fill($validated);

        if (is_string($rawPassword) && trim($rawPassword) !== '') {
            $cleaned = trim($rawPassword);
            if ($isGoogle) {
                // Strip all spaces from 16-character Google App Passwords
                $cleaned = preg_replace('/\s+/', '', $cleaned);
            }
            $configuration->password = $cleaned;
        }

        $configuration->save();

        return response()->json([
            'success' => true,
            'message' => 'Email settings saved. Send a test email to verify the SMTP account.',
            'data' => $this->safeConfiguration($configuration),
        ]);
    }

    public function test(Request $request, ConfiguredMailer $mailer, EmailFailureClassifier $classifier): JsonResponse
    {
        $validated = $request->validate(['recipient' => ['required', 'email:rfc', 'max:254']]);
        $configuration = EmailConfiguration::query()->first();
        if (! $configuration) {
            return response()->json(['success' => false, 'message' => 'Save an email configuration before testing it.'], 422);
        }

        $recipient = trim($validated['recipient']);

        $notification = EmailNotification::query()->create([
            'requested_by' => $request->user()?->id,
            'recipient_name' => null,
            'recipient_email' => $recipient,
            'type' => 'test',
            'status' => 'processing',
        ]);

        try {
            $mailer->send(
                $recipient,
                new EmailConnectionTestMail(
                    $configuration->sender_email,
                    (string) config('app.name', 'MasterExam')
                ),
                true
            );

            $configuration->forceFill([
                'is_connected' => true,
                'verified_at' => now(),
                'last_success_at' => now(),
            ])->save();

            $notification->forceFill([
                'status' => 'sent',
                'error_details' => null,
                'sent_at' => now(),
            ])->save();

            return response()->json([
                'success' => true,
                'message' => 'Accepted by the configured email provider. Check the recipient inbox and spam folder; delivery is not confirmed here.',
            ]);
        } catch (Throwable $exception) {
            $classified = $classifier->classify($exception, $configuration->provider);
            $safeDiagnostic = $classifier->safeDiagnostic($exception, [
                $this->revealPasswordSafely($configuration),
                $configuration->username,
            ]);

            $notification->forceFill([
                'status' => 'failed',
                'error_details' => $classified['message'],
            ])->save();

            $configuration->forceFill(['is_connected' => false])->save();

            Log::warning('Email connection test failed.', [
                'notification_id' => $notification->id,
                'error_code' => $classified['code'],
                'exception' => get_class($exception),
                'diagnostic' => $safeDiagnostic,
            ]);

            return response()->json([
                'success' => false,
                'message' => $classified['title'] . ': ' . $classified['message'],
                'error' => $classified['message'],
                'error_code' => $classified['code'],
                'help_url' => $classified['help_url'] ?? null,
            ], 422);
        }
    }

    public function disconnect(): JsonResponse
    {
        EmailConfiguration::query()->delete();
        return response()->json(['success' => true, 'message' => 'Saved email account disconnected.']);
    }

    public function history(Request $request): JsonResponse
    {
        $rows = EmailNotification::query()->latest()->paginate(min(100, max(1, (int) $request->query('per_page', 25))));
        return response()->json(['success' => true, 'data' => $rows->items(), 'meta' => [
            'current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total(),
        ]]);
    }

    public function retry(
        Request $request,
        EmailNotification $notification,
        ConfiguredMailer $mailer,
        EmailFailureClassifier $classifier,
        ExaminationPasskeyService $passkeys,
        ExaminationResultService $results
    ): JsonResponse {
        if ($notification->status !== 'failed') {
            return response()->json(['success' => false, 'message' => 'Only failed notifications can be retried.'], 422);
        }

        if ($notification->type === 'test') {
            $configuration = EmailConfiguration::query()->first();
            if (! $configuration) {
                return response()->json(['success' => false, 'message' => 'Save an email configuration before retrying.'], 422);
            }

            $notification->forceFill(['status' => 'processing'])->save();

            try {
                $mailer->send(
                    $notification->recipient_email,
                    new EmailConnectionTestMail(
                        $configuration->sender_email,
                        (string) config('app.name', 'MasterExam')
                    ),
                    true
                );

                $configuration->forceFill([
                    'is_connected' => true,
                    'verified_at' => now(),
                    'last_success_at' => now(),
                ])->save();

                $notification->forceFill([
                    'status' => 'sent',
                    'error_details' => null,
                    'sent_at' => now(),
                ])->save();

                return response()->json(['success' => true, 'message' => 'Test email sent successfully.']);
            } catch (Throwable $exception) {
                $classified = $classifier->classify($exception, $configuration->provider);
                $notification->forceFill([
                    'status' => 'failed',
                    'error_details' => $classified['message'],
                ])->save();

                $configuration->forceFill(['is_connected' => false])->save();

                return response()->json([
                    'success' => false,
                    'message' => $classified['title'] . ': ' . $classified['message'],
                    'error' => $classified['message'],
                    'error_code' => $classified['code'],
                    'help_url' => $classified['help_url'] ?? null,
                ], 422);
            }
        }

        if ($notification->type === 'passkey' && $notification->registration_id) {
            try {
                $passkeys->resend(ExaminationRegistration::query()->findOrFail($notification->registration_id), $request->user());
            } catch (Throwable $exception) {
                return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
            }
            return response()->json(['success' => true, 'message' => 'Examination passkey retry attempted.']);
        }

        if ($notification->type === 'result' && $notification->registration_id) {
            $results->createBatch($request->user(), [$notification->registration_id], ['mode' => 'retry', 'notification_id' => $notification->id]);
            return response()->json(['success' => true, 'message' => 'Finalized result notification queued for retry.']);
        }

        return response()->json(['success' => false, 'message' => 'This notification type cannot be retried.'], 422);
    }

    private function safeConfiguration(EmailConfiguration $configuration): array
    {
        $hasPassword = false;
        try {
            $hasPassword = ! empty($configuration->password);
        } catch (\Throwable) {
            $hasPassword = false;
        }

        return [
            'provider' => $configuration->provider,
            'sender_email' => $configuration->sender_email,
            'sender_name' => $configuration->sender_name,
            'host' => $configuration->host,
            'port' => (int) $configuration->port,
            'encryption' => $configuration->encryption,
            'username' => $configuration->username,
            'has_password' => $hasPassword,
            'connected' => (bool) $configuration->is_connected,
            'verified_at' => $configuration->verified_at,
            'last_success_at' => $configuration->last_success_at,
        ];
    }

    private function revealPasswordSafely(EmailConfiguration $configuration): ?string
    {
        try {
            return (string) $configuration->password;
        } catch (\Throwable) {
            return null;
        }
    }
}
