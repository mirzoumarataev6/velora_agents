<?php

namespace App\Policies;

use App\Models\BiWidget;
use App\Models\User;

/**
 * The widget's own gate.
 *
 * Note what it does NOT do: it never checks the dataset. A widget and the
 * dataset it reads are two separate authorizations, and the controller performs
 * both — this one says the card is yours, BiDatasetPolicy::query() says the
 * numbers are. Collapsing them into one check here would mean a widget whose
 * `bi_dataset_id` had been tampered with would be read with only the widget's
 * ownership proven, which is exactly the hole worth keeping two checks for.
 */
class BiWidgetPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, BiWidget $widget): bool
    {
        return $user->id === $widget->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, BiWidget $widget): bool
    {
        return $user->id === $widget->user_id;
    }

    public function delete(User $user, BiWidget $widget): bool
    {
        return $user->id === $widget->user_id;
    }
}
