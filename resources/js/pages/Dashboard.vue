<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3';
import DeliveryController from '@/actions/App/Http/Controllers/DeliveryController';
import EndpointController from '@/actions/App/Http/Controllers/EndpointController';
import HealthBadge from '@/components/endpoints/HealthBadge.vue';
import Heading from '@/components/Heading.vue';
import RelativeTime from '@/components/RelativeTime.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { percent } from '@/lib/format';
import { dashboard } from '@/routes';
import type { Endpoint } from '@/types';

type Summary = {
    window_hours: number;
    events_received: number;
    delivered: number;
    failed_attempts: number;
    dead_lettered: number;
    pending: number;
};

const props = defineProps<{
    summary: Summary;
    endpointCount: number;
    needsAttention: Endpoint[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
    },
});

usePoll(5000, { only: ['summary', 'needsAttention'] });

const tiles = [
    {
        label: 'Events received',
        value: () => props.summary.events_received,
        href: () => DeliveryController.index(),
    },
    {
        label: 'Delivered',
        value: () => props.summary.delivered,
        href: () =>
            DeliveryController.index({ query: { status: 'succeeded' } }),
    },
    {
        label: 'Failed attempts',
        value: () => props.summary.failed_attempts,
        href: () => DeliveryController.index({ query: { status: 'pending' } }),
    },
    {
        label: 'Dead-lettered',
        value: () => props.summary.dead_lettered,
        href: () => DeliveryController.index({ query: { status: 'dead' } }),
    },
];
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <Heading
            title="Overview"
            :description="`Last ${summary.window_hours} hours · ${summary.pending} pending now`"
        />

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Link
                v-for="tile in tiles"
                :key="tile.label"
                :href="tile.href()"
                class="rounded-xl border p-4 transition-colors hover:bg-muted/40"
                :data-test="`tile-${tile.label}`"
            >
                <p class="text-sm text-muted-foreground">{{ tile.label }}</p>
                <p class="mt-1 text-3xl font-semibold tabular-nums">
                    {{ tile.value().toLocaleString() }}
                </p>
            </Link>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>Needs attention</CardTitle>
            </CardHeader>
            <CardContent>
                <p
                    v-if="endpointCount === 0"
                    class="text-sm text-muted-foreground"
                >
                    No endpoints yet.
                    <Link
                        :href="EndpointController.create()"
                        class="underline underline-offset-4"
                        >Create one</Link
                    >
                    to start delivering events.
                </p>
                <p
                    v-else-if="needsAttention.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    All {{ endpointCount }} endpoints look fine.
                </p>
                <ul v-else class="divide-y">
                    <li
                        v-for="endpoint in needsAttention"
                        :key="endpoint.id"
                        class="flex flex-wrap items-center justify-between gap-2 py-3 first:pt-0 last:pb-0"
                    >
                        <div class="min-w-0">
                            <Link
                                :href="EndpointController.show(endpoint.id)"
                                class="block truncate font-medium hover:underline"
                            >
                                {{ endpoint.description ?? endpoint.url }}
                            </Link>
                            <p class="text-sm text-muted-foreground">
                                {{ endpoint.consecutive_failures }} failures in
                                a row ·
                                {{
                                    percent(
                                        endpoint.stats?.success_rate ?? null,
                                    )
                                }}
                                success · last attempt
                                <RelativeTime
                                    :value="
                                        endpoint.stats?.last_attempt_at ?? null
                                    "
                                    fallback="none recently"
                                />
                            </p>
                        </div>
                        <div class="flex items-center gap-3">
                            <HealthBadge
                                v-if="endpoint.health"
                                :health="endpoint.health"
                            />
                            <Button variant="outline" size="sm" as-child>
                                <Link
                                    :href="
                                        DeliveryController.index({
                                            query: { endpoint: endpoint.id },
                                        })
                                    "
                                    >Deliveries</Link
                                >
                            </Button>
                        </div>
                    </li>
                </ul>
            </CardContent>
        </Card>
    </div>
</template>
