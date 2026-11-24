<?php

namespace App\Observers;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use App\Exceptions\UserHasStaysException;

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
     * Перед удалением пользователя: запрет, если есть заселения.
     * @throws UserHasStaysException
     */
    public function deleting(User $user): void
    {
        if ($user->stays()->exists()) {
            throw new UserHasStaysException();
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