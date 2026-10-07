export type EndpointDisabledReason = 'manual' | 'circuit_breaker' | 'gone';

export type Endpoint = {
    id: string;
    url: string;
    description: string | null;
    event_types: string[];
    is_active: boolean;
    consecutive_failures: number;
    disabled_at: string | null;
    disabled_reason: EndpointDisabledReason | null;
    previous_secret_expires_at: string | null;
    created_at: string;
    updated_at: string;
};
