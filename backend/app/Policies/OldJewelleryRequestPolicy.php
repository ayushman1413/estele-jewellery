<?php

namespace App\Policies;

use App\Models\OldJewelleryRequest;
use App\Models\User;

class OldJewelleryRequestPolicy
{
    public function view(User $user, OldJewelleryRequest $oldJewelleryRequest): bool
    {
        return $user->id === $oldJewelleryRequest->user_id;
    }

    public function viewAny(User $user): bool
    {
        return true; // scoped to the user's own requests in the controller query
    }

    public function manageAsAdmin(User $user): bool
    {
        return $user->can('ViewAny:OldJewelleryRequest');
    }

    public function bidAsAdmin(User $user): bool
    {
        return $user->can('Update:OldJewelleryRequest');
    }
}
