<script setup lang="ts">
import { Form, Head, Link, setLayoutProps, usePoll } from '@inertiajs/vue3';
import { ChevronRight, RotateCcw } from '@lucide/vue';
import DeliveryController from '@/actions/App/Http/Controllers/DeliveryController';
import EndpointController from '@/actions/App/Http/Controllers/EndpointController';
import DeliveryStatusBadge from '@/components/deliveries/DeliveryStatusBadge.vue';
import Heading from '@/components/Heading.vue';
import JsonBlock from '@/components/JsonBlock.vue';
import RelativeTime from '@/components/RelativeTime.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { Delivery, DeliveryAttempt } from '@/types';

const props = defineProps<{
    delivery: Delivery;
    event: { id: string; type: string; received_at: string; payload: string };
    endpointActive: boolean;
}>();

setLayoutProps({
    breadcrumbs: [
        { title: 'Deliveries', href: DeliveryController.index() },
        {
            title: props.event.type,
            href: DeliveryController.show(props.delivery.id),
        },
    ],
});

usePoll(5000, { only: ['delivery', 'endpointActive'] });

function succeeded(attempt: DeliveryAttempt): boolean {
    return (
        attempt.status_code !== null &&
        attempt.status_code >= 200 &&
        attempt.status_code < 300
    );
}

function headersText(headers: Record<string, string>): string {
    return Object.entries(headers)
        .map(([name, value]) => `${name}: ${value}`)
        .join('\n');
}
</script>

<template>
    <Head :title="`${event.type} delivery`" />

    <div class="flex max-w-4xl flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                :title="event.type"
                :description="`Delivery ${delivery.id}`"
            />
            <div class="flex items-center gap-3">
                <DeliveryStatusBadge :status="delivery.status" />
                <Form
                    v-if="delivery.status === 'dead' && endpointActive"
                    v-bind="DeliveryController.replay.form(delivery.id)"
                    :options="{ preserveScroll: true }"
                    v-slot="{ processing }"
                >
                    <Button
                        type="submit"
                        size="sm"
                        :disabled="processing"
                        data-test="replay-delivery-button"
                    >
                        <RotateCcw />
                        Replay
                    </Button>
                </Form>
            </div>
        </div>

        <Card>
            <CardContent>
                <dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-muted-foreground">Endpoint</dt>
                        <dd>
                            <Link
                                v-if="
                                    delivery.endpoint &&
                                    !delivery.endpoint.deleted
                                "
                                :href="
                                    EndpointController.show(
                                        delivery.endpoint.id,
                                    )
                                "
                                class="break-all hover:underline"
                            >
                                {{ delivery.endpoint.url }}
                            </Link>
                            <span v-else class="break-all">
                                {{ delivery.endpoint?.url }} (deleted)
                            </span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Event</dt>
                        <dd class="font-mono break-all">{{ event.id }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Received</dt>
                        <dd><RelativeTime :value="event.received_at" /></dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Attempts this run</dt>
                        <dd class="tabular-nums">{{ delivery.attempts }}</dd>
                    </div>
                    <div v-if="delivery.status === 'pending'">
                        <dt class="text-muted-foreground">Next attempt</dt>
                        <dd>
                            <RelativeTime
                                :value="delivery.next_attempt_at"
                                fallback="as soon as possible"
                            />
                            <span
                                v-if="!endpointActive"
                                class="text-muted-foreground"
                            >
                                (waiting for the endpoint to be enabled)
                            </span>
                        </dd>
                    </div>
                    <div v-if="delivery.delivered_at">
                        <dt class="text-muted-foreground">Delivered</dt>
                        <dd><RelativeTime :value="delivery.delivered_at" /></dd>
                    </div>
                    <div v-if="delivery.replay_count > 0">
                        <dt class="text-muted-foreground">Replays</dt>
                        <dd>
                            {{ delivery.replay_count }}, last
                            <RelativeTime :value="delivery.last_replayed_at" />
                        </dd>
                    </div>
                </dl>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>Attempts</CardTitle>
                <CardDescription>
                    Every HTTP attempt, oldest first. The log is append-only and
                    survives replays.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <p
                    v-if="!delivery.attempt_log?.length"
                    class="text-sm text-muted-foreground"
                >
                    No attempts yet.
                </p>
                <ol v-else class="space-y-2">
                    <li
                        v-for="attempt in delivery.attempt_log"
                        :key="attempt.attempt"
                    >
                        <details
                            class="group rounded-lg border"
                            :data-test="`attempt-${attempt.attempt}`"
                        >
                            <summary
                                class="flex cursor-pointer list-none flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 text-sm"
                            >
                                <ChevronRight
                                    class="size-4 transition-transform group-open:rotate-90"
                                />
                                <span class="font-medium"
                                    >#{{ attempt.attempt }}</span
                                >
                                <span
                                    class="font-mono tabular-nums"
                                    :class="
                                        succeeded(attempt)
                                            ? 'text-emerald-600 dark:text-emerald-400'
                                            : 'text-red-600 dark:text-red-400'
                                    "
                                >
                                    {{ attempt.status_code ?? 'No response' }}
                                </span>
                                <span class="text-muted-foreground tabular-nums"
                                    >{{ attempt.duration_ms }} ms</span
                                >
                                <span
                                    class="min-w-0 flex-1 truncate text-muted-foreground"
                                    >{{ attempt.error }}</span
                                >
                                <RelativeTime
                                    :value="attempt.created_at"
                                    class="text-muted-foreground"
                                />
                            </summary>
                            <div class="space-y-4 border-t px-4 py-3">
                                <div v-if="attempt.error">
                                    <p
                                        class="mb-1 text-xs font-medium text-muted-foreground uppercase"
                                    >
                                        Error
                                    </p>
                                    <p class="text-sm break-all">
                                        {{ attempt.error }}
                                    </p>
                                </div>
                                <div>
                                    <p
                                        class="mb-1 text-xs font-medium text-muted-foreground uppercase"
                                    >
                                        Request headers
                                    </p>
                                    <JsonBlock
                                        :value="
                                            headersText(attempt.request_headers)
                                        "
                                    />
                                </div>
                                <div>
                                    <p
                                        class="mb-1 text-xs font-medium text-muted-foreground uppercase"
                                    >
                                        Response body (truncated)
                                    </p>
                                    <JsonBlock
                                        v-if="attempt.response_body"
                                        :value="attempt.response_body"
                                    />
                                    <p
                                        v-else
                                        class="text-sm text-muted-foreground"
                                    >
                                        Empty
                                    </p>
                                </div>
                            </div>
                        </details>
                    </li>
                </ol>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>Payload</CardTitle>
                <CardDescription>
                    Sent as <code class="font-mono">data</code> in the signed
                    envelope.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <JsonBlock :value="event.payload" />
            </CardContent>
        </Card>
    </div>
</template>
