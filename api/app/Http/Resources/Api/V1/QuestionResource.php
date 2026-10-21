<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $includeAnswer = $request->user()?->isAdmin();

        return [
            'id' => $this->id,
            'exam_subject_id' => $this->exam_subject_id,
            'stem' => $this->stem,
            'question_type' => $this->question_type,
            'options' => $this->options,
            'difficulty' => $this->difficulty,
            'status' => $this->status,
            'is_selected_for_exam' => (bool) $this->is_selected_for_exam,
            'sort_order' => (int) $this->sort_order,
            'correct_answer' => $this->when($includeAnswer, $this->correct_answer),
        ];
    }
}
