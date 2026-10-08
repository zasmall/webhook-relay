<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import EndpointController from '@/actions/App/Http/Controllers/EndpointController';
import HealthBadge from '@/components/endpoints/HealthBadge.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import RelativeTime from '@/components/RelativeTime.vue';
import { Button } from '@/components/ui/button';
import { percent } from '@/lib/format';
import type { Endpoint, EndpointDisabledReason } from '@/types';

defineProps<{ endpoints: Endpoint[] }>();

usePoll(5000, { only: ['endpoints'] });

const disabledReasons: Record<EndpointDisabledReason, string> = {
    manual: 'Turned off by an operator',
    circuit_breaker: 'Circuit breaker tripped',
    gone: 'Receiver answered 410 Gone',
};

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Endpoints', href: EndpointController.index() }],
    },
});
</script>

<template>
    <Head title="Endpoints" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <Heading
                title="Endpoints"
                description="Subscriber URLs that receive signed webhook deliveries"
            />
            <Button as-child>
                <Link :href="EndpointController.create()">
                    <Plus />
                    New endpoint
                </Link>
            </Button>
        </div>

        <div
            v-if="endpoints.length === 0"
            class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground"
        >
            No endpoints yet. Create one to start receiving events.
        </div>

        <div v-else class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead
                    class="border-b bg-muted/50 text-left text-muted-foreground"
                >
                    <tr>
                        <th class="px-4 py-3 font-medium">URL</th>
                        <th class="px-4 py-3 font-medium">Event types</th>
                        <th class="px-4 py-3 font-medium">Health</th>
                        <th class="px-4 py-3 text-right font-medium">
                            Success (24h)
                        </th>
                        <th class="px-4 py-3 text-right font-medium">
                            Pending
                        </th>
                        <th class="px-4 py-3 text-right font-medium">Dead</th>
                        <th class="px-4 py-3 font-medium">Last attempt</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="endpoint in endpoints"
                        :key="endpoint.id"
                        class="border-b last:border-0 hover:bg-muted/30"
                    >
                        <td class="max-w-md px-4 py-3">
                            <Link
                                :href="EndpointController.show(endpoint.id)"
                                class="block truncate font-medium hover:underline"
                            >
                                {{ endpoint.url }}
                            </Link>
                            <p
                                v-if="endpoint.description"
                                class="truncate text-muted-foreground"
                            >
                                {{ endpoint.description }}
                            </p>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-1">
                                <Badge
                                    v-for="type in endpoint.event_types"
                                    :key="type"
                                    variant="outline"
                                    class="font-mono"
                                >
                                    {{ type }}
                                </Badge>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <HealthBadge
                                v-if="endpoint.health"
                                :health="endpoint.health"
                            />
                            <p
                                v-if="
                                    !endpoint.is_active &&
                                    endpoint.disabled_reason
                                "
                                class="text-xs text-muted-foreground"
                            >
                                {{ disabledReasons[endpoint.disabled_reason] }}
                            </p>
                            <p
                                v-else-if="endpoint.consecutive_failures > 0"
                                class="text-xs text-muted-foreground"
                            >
                                {{ endpoint.consecutive_failures }} failures in
                                a row
                            </p>
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ percent(endpoint.stats?.success_rate ?? null) }}
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ endpoint.stats?.pending ?? 0 }}
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ endpoint.stats?.dead ?? 0 }}
                        </td>
                        <td class="px-4 py-3">
                            <RelativeTime
                                :value="endpoint.stats?.last_attempt_at ?? null"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
