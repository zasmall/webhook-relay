<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import EndpointController from '@/actions/App/Http/Controllers/EndpointController';
import EndpointStatusBadge from '@/components/endpoints/EndpointStatusBadge.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { Endpoint } from '@/types';

defineProps<{ endpoints: Endpoint[] }>();

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
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 text-right font-medium">
                            Failures in a row
                        </th>
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
                            <EndpointStatusBadge :endpoint="endpoint" />
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ endpoint.consecutive_failures }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
