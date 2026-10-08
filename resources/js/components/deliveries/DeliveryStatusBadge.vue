<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { DeliveryStatus } from '@/types';

defineProps<{ status: DeliveryStatus }>();

const variants: Record<
    DeliveryStatus,
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    succeeded: 'default',
    pending: 'outline',
    delivering: 'secondary',
    dead: 'destructive',
};
</script>

<template>
    <Badge :variant="variants[status]" class="capitalize">{{ status }}</Badge>
</template>
