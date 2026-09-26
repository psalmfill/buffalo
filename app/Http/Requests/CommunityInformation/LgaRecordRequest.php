<?php

namespace App\Http\Requests\CommunityInformation;

use App\Models\Community;
use App\Models\Lga;
use App\Support\LocalGovernment\CommunityRules;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base for records that belong to an LGA and (usually) one of its
 * communities. The LGA comes from the URL ({lga}, bound within the user's
 * jurisdiction) or from the record being edited; it cannot change.
 * Authorisation is in the controllers.
 */
abstract class LgaRecordRequest extends FormRequest
{
    /** Route parameter name of the record being edited. */
    abstract protected function recordParameter(): string;

    public function authorize(): bool
    {
        return true;
    }

    public function record(): ?Model
    {
        $record = $this->route($this->recordParameter());

        return $record instanceof Model ? $record : null;
    }

    public function lga(): Lga
    {
        $lga = $this->route('lga') ?? $this->record()?->getRelationValue('lga');

        abort_unless($lga instanceof Lga, 404);

        return $lga;
    }

    public function community(): ?Community
    {
        return app(CommunityRules::class)->communityOf($this->lga(), $this->input('community'));
    }

    /**
     * Rule: the value is the ULID of a community of this LGA.
     */
    protected function communityOfLga(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (app(CommunityRules::class)->communityOf($this->lga(), $value) === null) {
                $fail("The community must be a community of {$this->lga()->name} LGA.");
            }
        };
    }
}
