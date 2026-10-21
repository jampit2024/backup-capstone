<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailNotification extends Model
{
    protected $fillable = [
        'requested_by', 'registration_id', 'schedule_id', 'recipient_name', 'recipient_email',
        'type', 'status', 'error_details', 'sent_at',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }
}
