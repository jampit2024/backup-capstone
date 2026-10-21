<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuestionBankResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'school_year' => $this->school_year,
            'title' => $this->title,
            'is_active' => (bool) $this->is_active,
            'subjects_count' => $this->whenCounted('subjects'),
            'questions_count' => $this->whenCounted('questions'),
            'created_at' => optional($this->created_at)?->toISOString(),
            'updated_at' => optional($this->updated_at)?->toISOString(),
            'subjects' => QuestionSubjectResource::collection($this->whenLoaded('subjects')),
        ];
    }
}
