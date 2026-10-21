<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    public const SLUG_ADMIN = 'admin';

    public const SLUG_PROCTOR = 'proctor';

    public const ASSIGNABLE_SLUGS = [self::SLUG_ADMIN, self::SLUG_PROCTOR];

    protected $fillable = [
        'name',
        'slug',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function isAdmin(): bool
    {
        return $this->slug === self::SLUG_ADMIN;
    }

    public function isProctor(): bool
    {
        return $this->slug === self::SLUG_PROCTOR;
    }

    public function isAdminOrAbove(): bool
    {
        return $this->isAdmin();
    }
}
