<script setup lang="ts">
import { X } from '@lucide/vue';
import { ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Props = {
    id?: string;
    name?: string;
};

withDefaults(defineProps<Props>(), {
    id: 'event_types',
    name: 'event_types',
});

const model = defineModel<string[]>({ required: true });
const draft = ref('');

function add(): void {
    const values = draft.value
        .split(/[\s,]+/)
        .map((value) => value.trim())
        .filter((value) => value !== '' && !model.value.includes(value));

    model.value = [...model.value, ...new Set(values)];
    draft.value = '';
}

function remove(value: string): void {
    model.value = model.value.filter((item) => item !== value);
}
</script>

<template>
    <div class="space-y-3">
        <div class="flex gap-2">
            <Input
                :id="id"
                v-model="draft"
                placeholder="invoice.paid, invoice.*, or *"
                autocomplete="off"
                @keydown.enter.prevent="add"
            />
            <Button type="button" variant="outline" @click="add">Add</Button>
        </div>

        <div v-if="model.length > 0" class="flex flex-wrap gap-2">
            <Badge
                v-for="type in model"
                :key="type"
                variant="secondary"
                class="gap-1 font-mono"
            >
                {{ type }}
                <button
                    type="button"
                    class="rounded-sm opacity-70 hover:opacity-100"
                    :aria-label="`Remove ${type}`"
                    @click="remove(type)"
                >
                    <X class="size-3" />
                </button>
            </Badge>
        </div>
        <p v-else class="text-sm text-muted-foreground">
            No event types yet. Add at least one.
        </p>

        <input
            v-for="type in model"
            :key="type"
            type="hidden"
            :name="`${name}[]`"
            :value="type"
        />
    </div>
</template>
