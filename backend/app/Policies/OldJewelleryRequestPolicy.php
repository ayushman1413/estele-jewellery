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

    /**
     * Force-closing bidding selects the winner and credits the customer
     * wallet — a mutation, not a read, so it needs more than the read-level
     * permission manageAsAdmin() checks (which the vendor role also holds,
     * for viewing the requests it was invited to).
     */
    public function closeAsAdmin(User $user): bool
    {
        return $user->can('Update:OldJewelleryRequest');
    }
}
