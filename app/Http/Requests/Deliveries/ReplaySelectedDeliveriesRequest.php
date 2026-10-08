<?php

declare(strict_types=1);

namespace App\Http\Requests\Deliveries;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Delivery ids selected in the log; they may span endpoints.
 */
final class ReplaySelectedDeliveriesRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'delivery_ids' => ['required', 'array', 'list', 'min:1', 'max:500'],
            'delivery_ids.*' => ['required', 'string', 'ulid', 'distinct'],
        ];
    }

    /**
     * @return list<string>
     */
    public function deliveryIds(): array
    {
        return array_values(array_filter($this->array('delivery_ids'), is_string(...)));
    }
}
