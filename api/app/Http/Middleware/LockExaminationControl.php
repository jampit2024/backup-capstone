<?php

namespace App\Http\Middleware;

use App\Models\ExamSession;
use App\Models\ExaminationRegistration;
use App\Models\ExaminationSchedule;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LockExaminationControl
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->isMethod('GET')) {
            return $next($request);
        }
        return DB::transaction(function () use ($request, $next) {
            $session = $request->route('examSession') ?? $request->route('session');
            $registration = $request->route('registration');
            if ($registration instanceof ExaminationRegistration) {
                $session = $registration->examSession;
                abort_unless($session, 403, 'This student has no room session to control.');
            }
            $user = $request->user()
                ?: ($request->filled('proctor_id') ? User::find($request->input('proctor_id')) : null)
                ?: ($session instanceof ExamSession ? $session->proctor : null);

            abort_unless($user && ($user->isAdmin() || $user->isProctor()), 403, 'Proctor access only.');
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($registration instanceof ExaminationRegistration) {
                $session = $registration->examSession;
                abort_unless($session, 403, 'This student has no room session to control.');
            }
            $scheduleId = $session?->examination_schedule_id
                ?? $request->route('examinationSchedule')?->id
                ?? $request->input('examination_schedule_id');
            if ($scheduleId) {
                ExaminationSchedule::whereKey($scheduleId)->lockForUpdate()->firstOrFail();
            }
            if ($session instanceof ExamSession) {
                $session->refresh();
                ExamSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
                abort_unless($user->isAdmin() || $session->isOwnedBy($user), 403, 'This room is managed by another proctor.');
            }
            return $next($request);
        }, 3);
    }
}
