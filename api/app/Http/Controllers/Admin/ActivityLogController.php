<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ActivityLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:200'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'action' => ['nullable', 'string', 'max:80'],
            'sort' => ['nullable', 'in:created_at,user,action'],
            'dir' => ['nullable', 'in:asc,desc'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $this->filteredQuery($validated);
        $sort = $validated['sort'] ?? 'created_at';
        $direction = $validated['dir'] ?? 'desc';

        if ($sort === 'user') {
            $query->leftJoin('users', 'activity_logs.user_id', '=', 'users.id')
                ->select('activity_logs.*')
                ->orderBy('users.name', $direction);
        } else {
            $query->orderBy("activity_logs.{$sort}", $direction);
        }

        $page = $query->paginate($validated['per_page'] ?? 10);

        return response()->json([
            'success' => true,
            'data' => $page->getCollection()->map(fn (ActivityLog $log) => $this->map($log))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'users' => User::query()->select('id', 'name')->orderBy('name')->get(),
                'actions' => ActivityLog::query()->select('action')->distinct()->orderBy('action')->pluck('action'),
                'stats' => [
                    'total' => ActivityLog::query()->count(),
                    'active_users' => ActivityLog::query()->whereNotNull('user_id')->distinct('user_id')->count('user_id'),
                    'today' => ActivityLog::query()->whereDate('created_at', today())->count(),
                    'latest_at' => ActivityLog::query()->latest('created_at')->value('created_at'),
                ],
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:200'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'action' => ['nullable', 'string', 'max:80'],
            'sort' => ['nullable', 'in:created_at,user,action'],
            'dir' => ['nullable', 'in:asc,desc'],
        ]);

        $query = $this->filteredQuery($validated)->with('user:id,name');
        $sort = $validated['sort'] ?? 'created_at';
        $direction = $validated['dir'] ?? 'desc';
        if ($sort === 'user') {
            $query->leftJoin('users', 'activity_logs.user_id', '=', 'users.id')
                ->select('activity_logs.*')
                ->orderBy('users.name', $direction);
        } else {
            $query->orderBy("activity_logs.{$sort}", $direction);
        }

        return response()->streamDownload(function () use ($query): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['User', 'Action', 'Description', 'Date and time', 'Subject type', 'Subject ID']);

            $query->chunk(500, function ($logs) use ($output): void {
                foreach ($logs as $log) {
                    fputcsv($output, [
                        $this->safeCsvCell($log->user?->name ?? ($log->user_id ? 'Deleted user' : 'System')),
                        $this->safeCsvCell($log->action),
                        $this->safeCsvCell($log->description),
                        $log->created_at?->toISOString(),
                        $this->safeCsvCell($log->subject_type ?? ''),
                        $log->subject_id,
                    ]);
                }
            });

            fclose($output);
        }, 'activity-logs.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function filteredQuery(array $filters): Builder
    {
        return ActivityLog::query()->with('user:id,name')
            ->when($filters['user_id'] ?? null, fn (Builder $query, int $userId) => $query->where('user_id', $userId))
            ->when($filters['action'] ?? null, fn (Builder $query, string $action) => $query->where('action', $action))
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('action', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhereHas('user', fn (Builder $userQuery) => $userQuery->where('name', 'like', "%{$search}%"));

                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $search)) {
                        $query->orWhereDate('created_at', $search);
                    }
                });
            });
    }

    private function map(ActivityLog $log): array
    {
        return [
            'id' => $log->id,
            'user_id' => $log->user_id,
            'user_name' => $log->user?->name ?? ($log->user_id ? 'Deleted user' : 'System'),
            'action' => $log->action,
            'description' => $log->description,
            'subject_type' => $log->subject_type,
            'subject_id' => $log->subject_id,
            'created_at' => $log->created_at?->toISOString(),
        ];
    }

    private function safeCsvCell(string $value): string
    {
        return preg_match('/^[=+@\-\t\r]/', $value) ? "'{$value}" : $value;
    }
}
