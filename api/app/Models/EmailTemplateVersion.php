<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailTemplateVersion extends Model
{
    protected $fillable = ['type', 'status', 'content', 'created_by', 'published_at'];

    protected function casts(): array
    {
        return ['content' => 'array', 'published_at' => 'datetime'];
    }
}
