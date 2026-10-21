<?php

namespace App\Http\Controllers\Examination\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ExaminationScheduleResource;
use App\Models\ExaminationSchedule;
use App\Support\AcademicYear;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExaminationScheduleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ExaminationSchedule::query()
            ->when($request->user()?->isProctor(), fn ($query) => $query->inCurrentAcademicYear())
            ->with(['rooms.proctor:id,name'])
            ->withCount('registrations')
            ->orderBy('exam_date')
            ->orderBy('start_time');

        if ($date = $request->query('date')) {
            $query->whereDate('exam_date', $date);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $items = $query->paginate($request->integer('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => ExaminationScheduleResource::collection($items),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    public function show(Request $request, ExaminationSchedule $examinationSchedule): JsonResponse
    {
        if ($request->user()?->isProctor()) {
            abort_if(
                AcademicYear::isFutureCalendarYear($examinationSchedule->exam_date),
                403,
                AcademicYear::OUTSIDE_CURRENT_YEAR_MESSAGE
            );
            abort_unless(
                AcademicYear::isCurrent($examinationSchedule->academic_year),
                403,
                AcademicYear::OUTSIDE_CURRENT_YEAR_MESSAGE
            );
        }

        $examinationSchedule->load(['rooms.proctor:id,name'])->loadCount('registrations');

        return response()->json([
            'success' => true,
            'data' => new ExaminationScheduleResource($examinationSchedule),
        ]);
    }
}
