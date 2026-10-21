<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuestionSubjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'question_bank_id' => $this->question_bank_id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'selection_limit' => (int) $this->selection_limit,
            'is_active' => (bool) $this->is_active,
            'sort_order' => (int) $this->sort_order,
            'questions' => QuestionResource::collection($this->whenLoaded('questions')),
        ];
    }
}
