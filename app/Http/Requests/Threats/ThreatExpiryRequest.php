<?php

namespace App\Http\Requests\Threats;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A new expiry with a required comment (Phase 6B): Extend, or Reactivate.
 * In the future and at most `max_expiry_days` from now. Authorisation is
 * in the controller.
 */
class ThreatExpiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'expires_at' => ['required', 'date_format:'.ThreatReportRequest::DATETIME, function (string $attribute, mixed $value, Closure $fail): void {
                $error = ThreatReportRequest::expiryError($this->expiresAt());

                if ($error !== null) {
                    $fail($error);
                }
            }],
            'comment' => ['required', 'string', 'max:2000'],
        ];
    }

    public function expiresAt(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat(ThreatReportRequest::DATETIME, (string) $this->input('expires_at'), (string) config('app.timezone')) ?: CarbonImmutable::now();
    }

    public function comment(): string
    {
        return trim((string) $this->validated('comment'));
    }
}
