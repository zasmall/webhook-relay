<script setup lang="ts">
import { Form, Head, Link, router, setLayoutProps } from '@inertiajs/vue3';
import { Check, Copy, Eye, RotateCw } from '@lucide/vue';
import { computed, ref } from 'vue';
import EndpointController from '@/actions/App/Http/Controllers/EndpointController';
import EndpointStatusBadge from '@/components/endpoints/EndpointStatusBadge.vue';
import EventTypesInput from '@/components/endpoints/EventTypesInput.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { arrayFieldError } from '@/lib/validation';
import type { Endpoint } from '@/types';

const props = defineProps<{
    endpoint: Endpoint;
    secret: string | null;
}>();

setLayoutProps({
    breadcrumbs: [
        { title: 'Endpoints', href: EndpointController.index() },
        {
            title: props.endpoint.url,
            href: EndpointController.show(props.endpoint.id),
        },
    ],
});

const eventTypes = ref<string[]>([...props.endpoint.event_types]);
const copied = ref(false);
const rotateDialogOpen = ref(false);

const graceEndsAt = computed(() =>
    props.endpoint.previous_secret_expires_at
        ? new Date(props.endpoint.previous_secret_expires_at).toLocaleString()
        : null,
);

const disabledAt = computed(() =>
    props.endpoint.disabled_at
        ? new Date(props.endpoint.disabled_at).toLocaleString()
        : null,
);

async function copySecret(): Promise<void> {
    if (!props.secret) {
        return;
    }

    await navigator.clipboard.writeText(props.secret);
    copied.value = true;
    setTimeout(() => (copied.value = false), 2000);
}

function setActive(isActive: boolean): void {
    router.patch(
        EndpointController.update.url(props.endpoint.id),
        { is_active: isActive },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head :title="endpoint.url" />

    <div class="flex max-w-3xl flex-1 flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <Heading
                :title="endpoint.description ?? 'Endpoint'"
                :description="endpoint.url"
            />
            <EndpointStatusBadge :endpoint="endpoint" />
        </div>

        <Card>
            <CardHeader>
                <CardTitle>Status</CardTitle>
                <CardDescription v-if="endpoint.is_active">
                    Receiving deliveries.
                    {{ endpoint.consecutive_failures }} failures in a row.
                </CardDescription>
                <CardDescription v-else>
                    Disabled {{ disabledAt }}. Pending deliveries wait until the
                    endpoint is enabled again.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Button
                    v-if="endpoint.is_active"
                    variant="outline"
                    data-test="disable-endpoint-button"
                    @click="setActive(false)"
                >
                    Disable
                </Button>
                <Button
                    v-else
                    data-test="enable-endpoint-button"
                    @click="setActive(true)"
                >
                    Enable and reset failures
                </Button>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>Signing secret</CardTitle>
                <CardDescription>
                    Receivers verify the
                    <code class="font-mono">X-Relay-Signature</code> header with
                    this secret.
                </CardDescription>
            </CardHeader>
            <CardContent class="space-y-4">
                <div v-if="secret" class="flex gap-2">
                    <Input
                        :model-value="secret"
                        readonly
                        class="font-mono"
                        data-test="endpoint-secret"
                    />
                    <Button
                        variant="outline"
                        size="icon"
                        :aria-label="copied ? 'Copied' : 'Copy secret'"
                        @click="copySecret"
                    >
                        <Check v-if="copied" />
                        <Copy v-else />
                    </Button>
                </div>

                <p
                    v-if="graceEndsAt"
                    class="rounded-md bg-muted p-3 text-sm text-muted-foreground"
                >
                    The previous secret also signs deliveries until
                    {{ graceEndsAt }}.
                </p>

                <div class="flex flex-wrap gap-2">
                    <Button v-if="!secret" variant="outline" as-child>
                        <Link :href="EndpointController.secret(endpoint.id)">
                            <Eye />
                            Reveal secret
                        </Link>
                    </Button>

                    <Dialog v-model:open="rotateDialogOpen">
                        <DialogTrigger as-child>
                            <Button variant="outline">
                                <RotateCw />
                                Rotate secret
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle
                                    >Rotate the signing secret?</DialogTitle
                                >
                                <DialogDescription>
                                    A new secret is generated. The current one
                                    keeps signing deliveries during a grace
                                    window so you can update your receiver.
                                </DialogDescription>
                            </DialogHeader>
                            <DialogFooter class="gap-2">
                                <DialogClose as-child>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>
                                <Form
                                    v-bind="
                                        EndpointController.rotateSecret.form(
                                            endpoint.id,
                                        )
                                    "
                                    @success="rotateDialogOpen = false"
                                    v-slot="{ processing }"
                                >
                                    <Button
                                        type="submit"
                                        :disabled="processing"
                                        data-test="confirm-rotate-button"
                                    >
                                        Rotate secret
                                    </Button>
                                </Form>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </div>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>Settings</CardTitle>
            </CardHeader>
            <CardContent>
                <Form
                    v-bind="EndpointController.update.form(endpoint.id)"
                    :options="{ preserveScroll: true }"
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
                            :default-value="endpoint.url"
                        />
                        <InputError :message="errors.url" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="description">Description (optional)</Label>
                        <Input
                            id="description"
                            name="description"
                            :default-value="endpoint.description ?? ''"
                        />
                        <InputError :message="errors.description" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="event_types">Event types</Label>
                        <EventTypesInput v-model="eventTypes" />
                        <InputError
                            :message="arrayFieldError(errors, 'event_types')"
                        />
                    </div>

                    <Button
                        type="submit"
                        :disabled="processing || eventTypes.length === 0"
                        data-test="update-endpoint-button"
                    >
                        Save
                    </Button>
                </Form>
            </CardContent>
        </Card>

        <div
            class="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10"
        >
            <div class="space-y-0.5 text-red-600 dark:text-red-100">
                <p class="font-medium">Delete endpoint</p>
                <p class="text-sm">
                    No new deliveries will be created for it. Its delivery
                    history is kept.
                </p>
            </div>
            <Dialog>
                <DialogTrigger as-child>
                    <Button variant="destructive">Delete endpoint</Button>
                </DialogTrigger>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete this endpoint?</DialogTitle>
                        <DialogDescription>
                            {{ endpoint.url }} will stop receiving deliveries.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter class="gap-2">
                        <DialogClose as-child>
                            <Button variant="secondary">Cancel</Button>
                        </DialogClose>
                        <Form
                            v-bind="
                                EndpointController.destroy.form(endpoint.id)
                            "
                            v-slot="{ processing }"
                        >
                            <Button
                                type="submit"
                                variant="destructive"
                                :disabled="processing"
                                data-test="confirm-delete-endpoint-button"
                            >
                                Delete endpoint
                            </Button>
                        </Form>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    </div>
</template>
