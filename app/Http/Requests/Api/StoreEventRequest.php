<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Models\Source;
use App\Support\EventTypePattern;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;
use stdClass;

final class StoreEventRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:'.config()->integer('relay.ingest.max_idempotency_key_length')],
            'type' => ['required', 'string', 'max:255', 'regex:'.EventTypePattern::TYPE_REGEX],
            'payload' => [
                'required',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($this->decodedPayload() === null) {
                        $fail('The payload must be a non-empty JSON object.');
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'idempotency_key' => 'Idempotency-Key header',
        ];
    }

    public function source(): Source
    {
        $source = $this->user('sanctum');

        if (! $source instanceof Source) {
            throw new LogicException('Events can only be published by a source.');
        }

        return $source;
    }

    public function idempotencyKey(): string
    {
        return $this->string('idempotency_key')->value();
    }

    public function eventType(): string
    {
        return $this->string('type')->value();
    }

    /**
     * The payload decoded from the raw body as objects, so `{}` stays an object
     * instead of collapsing into an empty array.
     */
    public function payload(): stdClass
    {
        return $this->decodedPayload() ?? throw new LogicException('Payload read before validation.');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    private function decodedPayload(): ?stdClass
    {
        $body = json_decode($this->getContent());
        $payload = $body instanceof stdClass ? ($body->payload ?? null) : null;

        return $payload instanceof stdClass && get_object_vars($payload) !== [] ? $payload : null;
    }
}
