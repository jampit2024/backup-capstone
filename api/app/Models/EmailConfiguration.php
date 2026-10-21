<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailConfiguration extends Model
{
    protected $fillable = [
        'provider', 'sender_email', 'sender_name', 'host', 'port', 'encryption',
        'username', 'password', 'is_connected', 'verified_at', 'last_success_at', 'updated_by',
    ];

    protected $hidden = ['password', 'username'];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'is_connected' => 'boolean',
            'verified_at' => 'datetime',
            'last_success_at' => 'datetime',
        ];
    }
}
