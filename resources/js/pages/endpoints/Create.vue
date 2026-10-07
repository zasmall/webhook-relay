<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import EndpointController from '@/actions/App/Http/Controllers/EndpointController';
import EventTypesInput from '@/components/endpoints/EventTypesInput.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { arrayFieldError } from '@/lib/validation';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Endpoints', href: EndpointController.index() },
            { title: 'New endpoint', href: EndpointController.create() },
        ],
    },
});

const eventTypes = ref<string[]>(['*']);

const hasEventTypes = computed(() => eventTypes.value.length > 0);
</script>

<template>
    <Head title="New endpoint" />

    <div class="flex max-w-2xl flex-1 flex-col gap-6 p-4">
        <Heading
            title="New endpoint"
            description="A signing secret is generated for you after the endpoint is created"
        />

        <Form
            v-bind="EndpointController.store.form()"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="url">URL</Label>
                <Input
                    id="url"
                    name="url"
                    type="url"
                    required
                    placeholder="https://example.com/webhooks"
                />
                <InputError :message="errors.url" />
            </div>

            <div class="grid gap-2">
                <Label for="description">Description (optional)</Label>
                <Input
                    id="description"
                    name="description"
                    placeholder="Billing service"
                />
                <InputError :message="errors.description" />
            </div>

            <div class="grid gap-2">
                <Label for="event_types">Event types</Label>
                <EventTypesInput v-model="eventTypes" />
                <InputError :message="arrayFieldError(errors, 'event_types')" />
            </div>

            <div class="flex items-center gap-4">
                <Button
                    type="submit"
                    :disabled="processing || !hasEventTypes"
                    data-test="create-endpoint-button"
                >
                    Create endpoint
                </Button>
                <Button variant="ghost" as-child>
                    <Link :href="EndpointController.index()">Cancel</Link>
                </Button>
            </div>
        </Form>
    </div>
</template>
