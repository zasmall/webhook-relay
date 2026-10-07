<?php

declare(strict_types=1);

namespace App\Http\Requests\Endpoints;

use App\Rules\SafeWebhookUrl;
use App\Support\EventTypePattern;
use Closure;

/**
 * Validation shared by the API and the dashboard.
 */
trait EndpointRules
{
    /**
     * @return array<string, array<mixed>>
     */
    protected function endpointRules(): array
    {
        return [
            'url' => ['required', 'string', 'max:2048', 'url:http,https', app(SafeWebhookUrl::class)],
            'description' => ['nullable', 'string', 'max:255'],
            'event_types' => ['required', 'array', 'list', 'min:1', 'max:'.config()->integer('relay.endpoints.max_event_types')],
            'event_types.*' => [
                'required',
                'string',
                'max:255',
                'distinct',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && ! EventTypePattern::isValid($value)) {
                        $fail("\"{$value}\" is not a valid event type pattern. Use an exact type (invoice.paid), a prefix (invoice.*), or *.");
                    }
                },
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public function eventTypes(): array
    {
        return array_values(array_filter($this->array('event_types'), is_string(...)));
    }
}
