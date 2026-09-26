<?php

namespace App\Models\Concerns;

use App\Enums\Classification;
use App\Support\Audit\AuditLog;
use App\Support\Classification\ClassificationScope;

/**
 * Classified intelligence records (CLAUDE.md section 4a): a
 * `classification` column (cast to App\Enums\Classification) and a
 * `created_by` column. Adds ClassificationScope, which composes with
 * JurisdictionScope, and logs every change of level as a named event.
 *
 * The model must implement App\Support\Classification\Contracts\Classified.
 */
trait HasClassification
{
    public static function bootHasClassification(): void
    {
        static::addGlobalScope(new ClassificationScope);

        static::updated(function (self $model): void {
            if (! $model->wasChanged('classification')) {
                return;
            }

            $old = $model->getOriginal('classification');
            $new = $model->classificationLevel();
            $oldLevel = $old instanceof Classification ? $old : Classification::from((string) $old);

            AuditLog::event(
                'classification_changed',
                ($new->rank() > $oldLevel->rank() ? 'Classification raised' : 'Classification lowered')." from {$oldLevel->value} to {$new->value}",
                $model,
                ['old' => $oldLevel->value, 'new' => $new->value],
            );
        });
    }

    public function classificationLevel(): Classification
    {
        $level = $this->getAttribute('classification');

        return $level instanceof Classification ? $level : Classification::from((string) ($level ?? Classification::Restricted->value));
    }
}
