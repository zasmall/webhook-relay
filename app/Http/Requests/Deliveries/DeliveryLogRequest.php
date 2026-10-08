<?php

declare(strict_types=1);

namespace App\Http\Requests\Deliveries;

use App\Data\DeliveryLogFilters;
use App\Enums\DeliveryStatus;
use App\Support\EventTypePattern;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DeliveryLogRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(DeliveryStatus::class)],
            'endpoint' => ['nullable', 'ulid'],
            'event_type' => [
                'nullable',
                'string',
                'max:255',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && ! EventTypePattern::isValid($value)) {
                        $fail('Use an exact type (invoice.paid), a prefix (invoice.*), or *.');
                    }
                },
            ],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    public function filters(): DeliveryLogFilters
    {
        return new DeliveryLogFilters(
            status: $this->enum('status', DeliveryStatus::class),
            endpointId: $this->filled('endpoint') ? $this->string('endpoint')->value() : null,
            eventType: $this->filled('event_type') ? $this->string('event_type')->value() : null,
            from: $this->filled('from') ? CarbonImmutable::createFromFormat('Y-m-d', $this->string('from')->value()) ?: null : null,
            to: $this->filled('to') ? CarbonImmutable::createFromFormat('Y-m-d', $this->string('to')->value()) ?: null : null,
        );
    }
}
