<script setup lang="ts">
import { computed } from 'vue';
import { absoluteTime, relativeTime } from '@/lib/format';

const props = defineProps<{ value: string | null; fallback?: string }>();

const label = computed(() =>
    props.value ? relativeTime(props.value) : (props.fallback ?? '—'),
);
</script>

<template>
    <time
        v-if="value"
        :datetime="value"
        :title="absoluteTime(value)"
        class="whitespace-nowrap"
    >
        {{ label }}
    </time>
    <span v-else class="text-muted-foreground">{{ label }}</span>
</template>
