<?php

namespace App\Actions\ReferenceData;

use App\Models\IncidentType;
use App\Models\User;
use App\Support\DynamicFields\FieldDefinition;
use App\Support\DynamicFields\SchemaValidator;
use App\Support\ReferenceData\Contracts\ReferenceUsage;
use Illuminate\Validation\ValidationException;

/**
 * Replaces an incident type's detail fields. SchemaValidator is
 * authoritative: the schema must be valid and, once records use the type,
 * compatible with the current one. Each saved change increments
 * schema_version; an unchanged schema is a no-op.
 */
final class UpdateIncidentTypeSchema
{
    public function __construct(
        private readonly SchemaValidator $validator,
        private readonly ReferenceUsage $usage,
    ) {}

    /**
     * @return bool whether the schema changed
     *
     * @throws ValidationException
     */
    public function __invoke(IncidentType $type, mixed $schema, User $actor): bool
    {
        $proposed = $this->validator->validate($schema);
        $current = $this->validator->validate($type->details_schema);

        if ($this->usage->isInUse($type)) {
            $this->validator->assertCompatible($current, $proposed);
        }

        $normalised = self::normalise($proposed);

        // Compare canonical forms: jsonb does not keep object key order.
        if ($normalised === self::normalise($current)) {
            return false;
        }

        $type->forceFill([
            'details_schema' => $normalised,
            'schema_version' => $type->schema_version + 1,
            'updated_by' => $actor->id,
        ])->save();

        return true;
    }

    /**
     * @param  list<FieldDefinition>  $fields
     * @return list<array<string, mixed>>
     */
    private static function normalise(array $fields): array
    {
        return array_map(fn (FieldDefinition $field): array => $field->toArray(), $fields);
    }
}
