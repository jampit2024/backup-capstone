<?php

namespace App\Policies;

use App\Models\TestItem;
use App\Models\User;

class TestItemPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, TestItem $testItem): bool
    {
        return $user->isAdminOrAbove() || $testItem->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, TestItem $testItem): bool
    {
        return $user->isAdminOrAbove() || $testItem->user_id === $user->id;
    }

    public function delete(User $user, TestItem $testItem): bool
    {
        return $user->isAdminOrAbove() || $testItem->user_id === $user->id;
    }
}
