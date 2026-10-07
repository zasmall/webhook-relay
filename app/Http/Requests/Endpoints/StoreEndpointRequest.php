<?php

declare(strict_types=1);

namespace App\Http\Requests\Endpoints;

use Illuminate\Foundation\Http\FormRequest;

final class StoreEndpointRequest extends FormRequest
{
    use EndpointRules;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return $this->endpointRules();
    }
}
