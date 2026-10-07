<?php

declare(strict_types=1);

namespace App\Http\Requests\Deliveries;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Either {"delivery_ids": [...]} or {"all": true}, never both.
 */
final class ReplayDeliveriesRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'delivery_ids' => ['required_without:all', 'prohibits:all', 'array', 'list', 'min:1', 'max:500'],
            'delivery_ids.*' => ['required', 'string', 'ulid', 'distinct'],
            // "Neither" is caught by delivery_ids' required_without.
            'all' => ['sometimes', 'accepted'],
        ];
    }

    /**
     * The ids to replay, or null for every dead delivery.
     *
     * @return list<string>|null
     */
    public function deliveryIds(): ?array
    {
        if ($this->boolean('all')) {
            return null;
        }

        return array_values(array_filter($this->array('delivery_ids'), is_string(...)));
    }
}
