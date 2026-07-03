<?php

namespace App\Observers;

use App\Models\User;
use Illuminate\Support\Facades\Storage;

class UserObserver
{
    /**
     * Перед обновлением пользователя.
     */
    public function updating(User $user): void
    {
        if (! $user->isDirty('docs')) {
            return;
        }

        $oldFile = $user->getOriginal('docs');

        if ($oldFile && Storage::exists($oldFile)) {
            Storage::delete($oldFile);
        }
    }

    /**
     * После удаления пользователя.
     */
    public function deleted(User $user): void
    {
        if ($user->docs && Storage::exists($user->docs)) {
            Storage::delete($user->docs);
        }
    }
}