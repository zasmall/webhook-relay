export type DeliveryStatus = 'pending' | 'delivering' | 'succeeded' | 'dead';

export type DeliveryAttempt = {
    attempt: number;
    status_code: number | null;
    error: string | null;
    duration_ms: number;
    request_headers: Record<string, string>;
    response_body: string | null;
    created_at: string;
};

export type Delivery = {
    id: string;
    event_id: string;
    endpoint_id: string;
    event_type?: string;
    endpoint?: {
        id: string;
        url: string;
        description: string | null;
        deleted: boolean;
    };
    status: DeliveryStatus;
    attempts: number;
    next_attempt_at: string | null;
    last_status_code: number | null;
    delivered_at: string | null;
    replay_count: number;
    last_replayed_at: string | null;
    created_at: string;
    attempt_log?: DeliveryAttempt[];
};
