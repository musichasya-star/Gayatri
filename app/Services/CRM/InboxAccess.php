<?php

namespace App\Services\CRM;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class InboxAccess
{
    public static function query(User $actor): Builder
    {
        $query = Conversation::query();
        if ($actor->role === 'therapist') {
            $profile = $actor->therapistProfile;
            $query->where('assigned_user_id', $actor->id);
            if (! $profile || $profile->status !== 'active') {
                return $query->whereRaw('1 = 0');
            }
            if ($profile->branch_id) {
                $query->whereHas('customer', fn ($q) => $q->where('branch_id', $profile->branch_id));
            }
        }

        return $query;
    }

    public static function authorize(Conversation $conversation, User $actor): void
    {
        abort_unless($actor->status === 'active' && self::query($actor)->whereKey($conversation->id)->exists(), 404);
    }
}
