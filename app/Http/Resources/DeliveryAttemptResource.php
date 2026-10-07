<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DeliveryAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DeliveryAttempt
 */
final class DeliveryAttemptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'attempt' => $this->attempt,
            'status_code' => $this->status_code,
            'error' => $this->error,
            'duration_ms' => $this->duration_ms,
            'request_headers' => $this->request_headers,
            'response_body' => $this->response_body,
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
