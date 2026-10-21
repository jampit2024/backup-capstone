<?php

namespace App\Http\Controllers\Result;

use App\Http\Controllers\Controller;
use App\Services\ResultsDashboardService;
use App\Support\AcademicYear;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ResultsDashboardController extends Controller
{
    public function __construct(private ResultsDashboardService $results)
    {
    }

    public function years(): JsonResponse
    {
        return response()->json($this->results->years());
    }

    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'year' => ['required', 'regex:/^\d{4}-\d{4}$/'],
            'date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'method' => ['sometimes', 'in:all,online,manual'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->first());
        }

        $year = (string) $request->query('year');
        if (! $this->hasYear($year)) {
            return response()->json(['error' => 'Academic year not found.'], 404);
        }

        $method = $request->query('method');
        return response()->json($this->results->results($year, $request->query('date'), $method === 'all' ? null : $method));
    }

    public function search(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'q' => ['required', 'string', 'min:2', 'max:120'],
            'method' => ['sometimes', 'in:all,online,manual'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->first());
        }

        $method = $request->query('method');
        return response()->json($this->results->search(trim((string) $request->query('q')), $method === 'all' ? null : $method));
    }

    public function show(Request $request, string $applicantId): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'year' => ['sometimes', 'regex:/^\d{4}-\d{4}$/'],
            'schedule_id' => ['sometimes', 'integer', 'exists:exam_schedules,id'],
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator->errors()->first());
        }

        try {
            return response()->json($this->results->applicant(
                $applicantId,
                $request->query('year'),
                $request->integer('schedule_id') ?: null
            ));
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Applicant result not found.'], 404);
        }
    }

    public function send(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'year' => ['required', 'regex:/^\d{4}-\d{4}$/'],
            'mode' => ['required', 'in:batch,date,all'],
            'batches' => ['required_if:mode,batch', 'array', 'min:1'],
            'batches.*' => ['integer', 'distinct', 'exists:exam_schedules,id'],
            'date' => ['nullable', 'required_if:mode,date', 'date_format:Y-m-d'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->first());
        }

        $year = (string) $request->input('year');
        if (str_replace('–', '-', $year) !== str_replace('–', '-', AcademicYear::current())) {
            return response()->json(['error' => 'Archived academic years are read-only.'], 403);
        }

        $queued = $this->results->queueResults(
            $request->user(),
            $year,
            (string) $request->input('mode'),
            $request->only(['batches', 'date'])
        );

        return response()->json([
            'count' => $queued['count'],
            'batchId' => $queued['batchId'],
            'message' => $queued['count'] > 0
                ? "Queued {$queued['count']} applicant result notification(s)."
                : 'No eligible unsent results were found.',
        ]);
    }

    public function sendApplicant(Request $request, string $applicantId): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'year' => ['sometimes', 'regex:/^\d{4}-\d{4}$/'],
            'schedule_id' => ['sometimes', 'integer', 'exists:exam_schedules,id'],
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator->errors()->first());
        }

        try {
            $scheduleId = $request->integer('schedule_id') ?: null;
            $result = $this->results->applicant($applicantId, $request->query('year'), $scheduleId);
            if (str_replace('–', '-', (string) $result['academicYear']) !== str_replace('–', '-', AcademicYear::current())) {
                return response()->json(['error' => 'Archived academic years are read-only.'], 403);
            }

            $queued = $this->results->queueApplicant($request->user(), $applicantId, $result['academicYear'], $scheduleId);
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Applicant result not found.'], 404);
        } catch (Throwable $error) {
            return response()->json(['error' => $error->getMessage()], 422);
        }

        return response()->json([
            'count' => $queued['count'],
            'batchId' => $queued['batchId'],
            'message' => $queued['count'] > 0
                ? 'Applicant result notification queued.'
                : 'An applicant result notification is already queued.',
        ]);
    }

    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'year' => ['required', 'regex:/^\d{4}-\d{4}$/'],
            'date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'method' => ['sometimes', 'in:all,online,manual'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->first());
        }

        $year = (string) $request->query('year');
        if (! $this->hasYear($year)) {
            return response()->json(['error' => 'Academic year not found.'], 404);
        }

        $date = $request->query('date');
        $filename = 'exam-results-'.str_replace('-', '_', $year).($date ? '-'.$date : '').'.csv';

        $method = $request->query('method');
        return response()->streamDownload(function () use ($year, $date, $method): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, [
                'Applicant ID', 'Applicant Name', 'Program', 'Examination Title', 'Academic Year', 'Batch', 'Exam Date',
                'Exam Time', 'Room', 'Score', 'Percentage', 'Examination Method', 'Result Status', 'Notification Email', 'Sent At',
            ]);

            foreach ($this->results->exportQuery($year, $date, $method === 'all' ? null : $method)->lazy(500) as $row) {
                $item = $this->results->mapForExport($row);
                fputcsv($output, [
                    $item['applicantCode'], $item['name'], $item['program'], $item['examTitle'], $item['academicYear'], $item['batch'],
                    $item['examDate'], $item['time'], $item['room'],
                    $item['displayScore'], $item['percentage'],
                    $item['examinationMethod'], $item['status'],
                    $item['email'], $item['sentAt'],
                ]);
            }

            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function hasYear(string $year): bool
    {
        if (preg_match('/^\d{4}-\d{4}$/', $year)) {
            return true;
        }

        return collect($this->results->years())->contains(fn (array $item) => $item['year'] === $year);
    }

    private function validationError(string $message): JsonResponse
    {
        return response()->json(['error' => $message], 422);
    }
}
