<script setup lang="ts">
import { computed } from 'vue';
import type { EndpointHealth } from '@/types';

const props = defineProps<{ health: EndpointHealth }>();

const styles: Record<EndpointHealth, { label: string; dot: string }> = {
    healthy: { label: 'Healthy', dot: 'bg-emerald-500' },
    degraded: { label: 'Degraded', dot: 'bg-amber-500' },
    failing: { label: 'Failing', dot: 'bg-red-500' },
    disabled: { label: 'Disabled', dot: 'bg-neutral-400' },
    idle: { label: 'Idle', dot: 'bg-neutral-300 dark:bg-neutral-600' },
};

const style = computed(() => styles[props.health]);
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 text-sm whitespace-nowrap"
        :data-health="health"
    >
        <span
            class="size-2 rounded-full"
            :class="style.dot"
            aria-hidden="true"
        />
        {{ style.label }}
    </span>
</template>
