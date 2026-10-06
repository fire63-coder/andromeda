<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\User;

/**
 * Formateurs : créent et modifient leurs cours, peuvent les soumettre en relecture.
 * Administrateurs : tout, dont la publication.
 */
class CoursePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAuthorContent();
    }

    public function create(User $user): bool
    {
        return $user->canAuthorContent();
    }

    public function update(User $user, Course $course): bool
    {
        return $user->isAdmin() || ($user->canAuthorContent() && $course->author_id === $user->id);
    }

    public function publish(User $user): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Course $course): bool
    {
        return $user->isAdmin();
    }
}
