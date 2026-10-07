<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import type { Endpoint } from '@/types';

const props = defineProps<{ endpoint: Endpoint }>();

const reasons: Record<NonNullable<Endpoint['disabled_reason']>, string> = {
    manual: 'Disabled',
    circuit_breaker: 'Disabled: failing',
    gone: 'Disabled: gone',
};

const label = computed(() =>
    props.endpoint.is_active
        ? 'Active'
        : reasons[props.endpoint.disabled_reason ?? 'manual'],
);
</script>

<template>
    <Badge
        :variant="
            endpoint.is_active
                ? 'default'
                : endpoint.disabled_reason === 'manual'
                  ? 'secondary'
                  : 'destructive'
        "
    >
        {{ label }}
    </Badge>
</template>
