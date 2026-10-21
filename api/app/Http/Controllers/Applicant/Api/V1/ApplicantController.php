<?php

namespace App\Http\Controllers\Applicant\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ApplicantResource;
use App\Models\Applicant;
use App\Services\ApplicantImportParser;
use App\Services\ApplicantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ApplicantController extends Controller
{
    public function __construct(
        private ApplicantService $applicants,
        private ApplicantImportParser $importParser,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $page = $this->applicants->list($request->all(), $request->integer('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => collect($page->items())->map(fn (Applicant $a) => $this->applicants->mapRow($a))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(Applicant $applicant): JsonResponse
    {
        $applicant->load(['latestRegistration.schedule']);

        return response()->json([
            'success' => true,
            'data' => $this->applicants->mapRow($applicant),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'desired_program' => ['required', 'string', 'max:100'],
            'course_applied' => ['nullable', 'string', 'max:100'],
            'application_date' => ['nullable', 'date'],
            'preferred_exam_time' => ['nullable', 'string', 'max:40'],
            'time' => ['nullable', 'string', 'max:40'],
            'gmail' => ['nullable', 'email', 'max:255'],
            // Profile comes from third-party import: name, program, date, time, gmail.
        ]);

        if (isset($validated['email'])) {
            unset($validated['email']);
        }

        $applicant = $this->applicants->create($validated);

        return response()->json([
            'success' => true,
            'data' => $this->applicants->mapRow($applicant),
            'message' => 'Applicant registered successfully.',
        ], 201);
    }

    public function update(Request $request, Applicant $applicant): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'desired_program' => ['sometimes', 'string', 'max:100'],
            'course_applied' => ['sometimes', 'nullable', 'string', 'max:100'],
            'application_date' => ['sometimes', 'nullable', 'date'],
            'preferred_exam_time' => ['sometimes', 'nullable', 'string', 'max:40'],
            'time' => ['sometimes', 'nullable', 'string', 'max:40'],
            'gmail' => ['sometimes', 'nullable', 'email', 'max:255'],
        ]);

        $applicant = $this->applicants->update($applicant, $validated);

        return response()->json([
            'success' => true,
            'data' => $this->applicants->mapRow($applicant),
        ]);
    }

    public function submitGmail(Request $request, Applicant $applicant): JsonResponse
    {
        $validated = $request->validate([
            'gmail' => ['required', 'email', 'max:255'],
        ]);

        $applicant = $this->applicants->submitGmail($applicant, $validated['gmail']);

        return response()->json([
            'success' => true,
            'data' => $this->applicants->mapRow($applicant),
            'message' => 'Gmail saved. Your result will be sent to this address.',
        ]);
    }

    public function import(Request $request): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'extensions:csv,txt,xlsx,xls',
                'max:10240',
            ],
        ], [
            'file.extensions' => 'Please upload a CSV or Excel file (.csv, .xlsx, or .xls).',
        ]);

        // Large spreadsheets outlive the default limits; a fatal here would return
        // a response with no CORS headers, which the browser reports as a CORS error.
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        try {
            $rows = $this->importParser->parse($validated['file']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $result = $this->applicants->importRows($rows);

        $parts = [];
        if (($result['imported'] ?? 0) > 0) {
            $parts[] = "Imported {$result['imported']} new student(s)";
        }
        if (($result['updated'] ?? 0) > 0) {
            $parts[] = "updated {$result['updated']} existing student(s)";
        }
        if (($result['gmails_updated'] ?? 0) > 0) {
            $parts[] = "{$result['gmails_updated']} Gmail address(es) filled/updated";
        }
        if (($result['duplicates'] ?? 0) > 0) {
            $parts[] = "{$result['duplicates']} duplicate name(s) inside the file were ignored";
        }
        if (($result['skipped'] ?? 0) > 0) {
            $parts[] = "{$result['skipped']} invalid row(s)";
        }
        if ($parts === []) {
            $parts[] = 'No changes from this file';
        }
        $parts[] = 'Student List now has '.($result['student_list_total'] ?? 0).' applicant(s)';

        return response()->json([
            'success' => true,
            'message' => implode('. ', $parts).'.',
            'data' => $result,
        ]);
    }
}
