<script setup lang="ts">
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import { RotateCcw, X } from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import DeliveryController from '@/actions/App/Http/Controllers/DeliveryController';
import DeliveryStatusBadge from '@/components/deliveries/DeliveryStatusBadge.vue';
import Heading from '@/components/Heading.vue';
import RelativeTime from '@/components/RelativeTime.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Delivery, DeliveryStatus } from '@/types';

type Filters = Partial<
    Record<'status' | 'endpoint' | 'event_type' | 'from' | 'to', string>
>;

const props = defineProps<{
    deliveries: {
        data: Delivery[];
        next_page_url: string | null;
        prev_page_url: string | null;
    };
    filters: Filters;
    statuses: DeliveryStatus[];
    endpoints: { id: string; label: string; deleted: boolean }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Deliveries', href: DeliveryController.index() },
        ],
    },
});

usePoll(5000, { only: ['deliveries'] });

const form = reactive<Required<Filters>>({
    status: props.filters.status ?? '',
    endpoint: props.filters.endpoint ?? '',
    event_type: props.filters.event_type ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

const hasFilters = computed(() => Object.values(props.filters).length > 0);

function apply(): void {
    const query = Object.fromEntries(
        Object.entries(form).filter(([, value]) => value !== ''),
    );

    router.get(DeliveryController.index.url({ query }), undefined, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function clear(): void {
    Object.assign(form, {
        status: '',
        endpoint: '',
        event_type: '',
        from: '',
        to: '',
    });
    apply();
}

// Selection: only dead deliveries can be replayed.
const selected = ref<string[]>([]);
const deadOnPage = computed(() =>
    props.deliveries.data.filter((delivery) => delivery.status === 'dead'),
);

watch(
    () => props.deliveries.data,
    (rows) => {
        const dead = new Set(
            rows.filter((r) => r.status === 'dead').map((r) => r.id),
        );
        selected.value = selected.value.filter((id) => dead.has(id));
    },
);

const allSelected = computed(
    () =>
        deadOnPage.value.length > 0 &&
        deadOnPage.value.every((d) => selected.value.includes(d.id)),
);

function toggleAll(checked: boolean | 'indeterminate'): void {
    selected.value = checked === true ? deadOnPage.value.map((d) => d.id) : [];
}

function toggle(id: string, checked: boolean | 'indeterminate'): void {
    selected.value =
        checked === true
            ? [...selected.value, id]
            : selected.value.filter((item) => item !== id);
}

const replaying = ref(false);

function replaySelected(): void {
    router.post(
        DeliveryController.replaySelected.url(),
        { delivery_ids: selected.value },
        {
            preserveScroll: true,
            onStart: () => (replaying.value = true),
            onFinish: () => (replaying.value = false),
            onSuccess: () => (selected.value = []),
        },
    );
}

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs dark:bg-input/30';
</script>

<template>
    <Head title="Deliveries" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <Heading
            title="Deliveries"
            description="Every event sent to every endpoint, newest first"
        />

        <form
            class="grid gap-3 rounded-xl border p-4 sm:grid-cols-2 lg:grid-cols-6"
            @submit.prevent="apply"
        >
            <div class="grid gap-1.5">
                <Label for="status">Status</Label>
                <select
                    id="status"
                    v-model="form.status"
                    :class="selectClass"
                    @change="apply"
                >
                    <option value="">Any</option>
                    <option
                        v-for="status in statuses"
                        :key="status"
                        :value="status"
                    >
                        {{ status.charAt(0).toUpperCase() + status.slice(1) }}
                    </option>
                </select>
            </div>
            <div class="grid gap-1.5 lg:col-span-2">
                <Label for="endpoint">Endpoint</Label>
                <select
                    id="endpoint"
                    v-model="form.endpoint"
                    :class="selectClass"
                    @change="apply"
                >
                    <option value="">Any</option>
                    <option
                        v-for="endpoint in endpoints"
                        :key="endpoint.id"
                        :value="endpoint.id"
                    >
                        {{ endpoint.label
                        }}{{ endpoint.deleted ? ' (deleted)' : '' }}
                    </option>
                </select>
            </div>
            <div class="grid gap-1.5">
                <Label for="event_type">Event type</Label>
                <Input
                    id="event_type"
                    v-model="form.event_type"
                    placeholder="invoice.*"
                    autocomplete="off"
                />
            </div>
            <div class="grid gap-1.5">
                <Label for="from">From</Label>
                <Input
                    id="from"
                    v-model="form.from"
                    type="date"
                    @change="apply"
                />
            </div>
            <div class="grid gap-1.5">
                <Label for="to">To</Label>
                <Input id="to" v-model="form.to" type="date" @change="apply" />
            </div>
            <div class="flex gap-2 sm:col-span-2 lg:col-span-6">
                <Button type="submit" size="sm">Apply</Button>
                <Button
                    v-if="hasFilters"
                    type="button"
                    variant="ghost"
                    size="sm"
                    @click="clear"
                >
                    <X />
                    Clear filters
                </Button>
            </div>
        </form>

        <div
            v-if="selected.length > 0"
            class="flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-muted/40 px-4 py-2"
        >
            <p class="text-sm">{{ selected.length }} selected</p>
            <Button
                size="sm"
                :disabled="replaying"
                data-test="replay-selected-button"
                @click="replaySelected"
            >
                <RotateCcw />
                Replay selected
            </Button>
        </div>

        <div
            v-if="deliveries.data.length === 0"
            class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground"
        >
            No deliveries{{ hasFilters ? ' match these filters' : ' yet' }}.
        </div>

        <div v-else class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead
                    class="border-b bg-muted/50 text-left text-muted-foreground"
                >
                    <tr>
                        <th class="w-10 px-4 py-3">
                            <Checkbox
                                :model-value="allSelected"
                                :disabled="deadOnPage.length === 0"
                                aria-label="Select all dead deliveries on this page"
                                @update:model-value="toggleAll"
                            />
                        </th>
                        <th class="px-4 py-3 font-medium">Event</th>
                        <th class="px-4 py-3 font-medium">Endpoint</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 text-right font-medium">
                            Attempts
                        </th>
                        <th class="px-4 py-3 text-right font-medium">
                            Last code
                        </th>
                        <th class="px-4 py-3 font-medium">Created</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="delivery in deliveries.data"
                        :key="delivery.id"
                        class="border-b last:border-0 hover:bg-muted/30"
                    >
                        <td class="px-4 py-3">
                            <Checkbox
                                v-if="delivery.status === 'dead'"
                                :model-value="selected.includes(delivery.id)"
                                :aria-label="`Select delivery ${delivery.id}`"
                                @update:model-value="
                                    (checked) => toggle(delivery.id, checked)
                                "
                            />
                        </td>
                        <td class="px-4 py-3">
                            <Link
                                :href="DeliveryController.show(delivery.id)"
                                class="font-mono font-medium hover:underline"
                            >
                                {{ delivery.event_type }}
                            </Link>
                            <p
                                v-if="delivery.replay_count > 0"
                                class="text-xs text-muted-foreground"
                            >
                                Replayed {{ delivery.replay_count }}×
                            </p>
                        </td>
                        <td
                            class="max-w-xs truncate px-4 py-3"
                            :title="delivery.endpoint?.url"
                        >
                            {{
                                delivery.endpoint?.description ??
                                delivery.endpoint?.url
                            }}
                            <span
                                v-if="delivery.endpoint?.deleted"
                                class="text-muted-foreground"
                                >(deleted)</span
                            >
                        </td>
                        <td class="px-4 py-3">
                            <DeliveryStatusBadge :status="delivery.status" />
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ delivery.attempts }}
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ delivery.last_status_code ?? '—' }}
                        </td>
                        <td class="px-4 py-3">
                            <RelativeTime :value="delivery.created_at" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="deliveries.prev_page_url || deliveries.next_page_url"
            class="flex justify-between"
        >
            <Button
                variant="outline"
                size="sm"
                :disabled="!deliveries.prev_page_url"
                as-child
            >
                <Link
                    v-if="deliveries.prev_page_url"
                    :href="deliveries.prev_page_url"
                    preserve-scroll
                    >Newer</Link
                >
                <span v-else>Newer</span>
            </Button>
            <Button
                variant="outline"
                size="sm"
                :disabled="!deliveries.next_page_url"
                as-child
            >
                <Link
                    v-if="deliveries.next_page_url"
                    :href="deliveries.next_page_url"
                    preserve-scroll
                    >Older</Link
                >
                <span v-else>Older</span>
            </Button>
        </div>
    </div>
</template>
