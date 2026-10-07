<?php

declare(strict_types=1);

namespace App\Http\Requests\Endpoints;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Partial update: only the fields that are sent are validated and applied.
 */
final class UpdateEndpointRequest extends FormRequest
{
    use EndpointRules;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $rules = array_map(
            fn (array $rules): array => ['sometimes', ...$rules],
            $this->endpointRules(),
        );

        $rules['is_active'] = ['sometimes', 'boolean'];

        return $rules;
    }

    /**
     * @return array{url?: string, description?: string|null, event_types?: list<string>, is_active?: bool}
     */
    public function endpointAttributes(): array
    {
        $attributes = [];

        if ($this->has('url')) {
            $attributes['url'] = $this->string('url')->value();
        }

        if ($this->has('description')) {
            $attributes['description'] = $this->filled('description') ? $this->string('description')->value() : null;
        }

        if ($this->has('event_types')) {
            $attributes['event_types'] = $this->eventTypes();
        }

        if ($this->has('is_active')) {
            $attributes['is_active'] = $this->boolean('is_active');
        }

        return $attributes;
    }
}
