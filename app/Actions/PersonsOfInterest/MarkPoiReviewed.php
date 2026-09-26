<?php

namespace App\Actions\PersonsOfInterest;

use App\Models\PersonOfInterest;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Records a review of a POI, with a comment, and sets the next review date
 * (by default BUFFALO_POI_REVIEW_MONTHS months ahead).
 */
final class MarkPoiReviewed
{
    public function __invoke(PersonOfInterest $poi, string $comment, ?string $nextReview, User $actor): void
    {
        DB::transaction(function () use ($poi, $comment, $nextReview, $actor): void {
            $next = $nextReview ?? DesignatePersonOfInterest::nextReview();

            $poi->forceFill([
                'last_reviewed_at' => now(),
                'last_reviewed_by' => $actor->id,
                'review_due_at' => $next,
                'updated_by' => $actor->id,
            ])->save();

            AuditLog::event('poi_reviewed', 'Reviewed', $poi, ['comment' => $comment, 'next_review' => $next], $actor);
        });
    }
}
