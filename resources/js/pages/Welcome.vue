<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    Fingerprint,
    KeyRound,
    RefreshCcw,
    RotateCcw,
    ShieldCheck,
    Zap,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { dashboard, login } from '@/routes';

defineProps<{
    demoLogin: { email: string; password: string } | null;
}>();

const page = usePage();
const signedIn = computed(() => Boolean(page.props.auth?.user));

const steps = [
    {
        title: 'Ingest',
        body: 'A source app POSTs an event once, with an Idempotency-Key. Retries return the original event.',
    },
    {
        title: 'Fan out',
        body: 'Each endpoint whose patterns match (invoice.paid, invoice.*, *) gets its own delivery.',
    },
    {
        title: 'Deliver',
        body: 'The payload is signed with HMAC-SHA256 and POSTed to a re-checked, pinned public address.',
    },
    {
        title: 'Retry',
        body: 'Full-jitter backoff, Retry-After on 429, give up on 410, and a circuit breaker for dead receivers.',
    },
    {
        title: 'Recover',
        body: 'Every attempt is logged. Dead deliveries can be replayed once the receiver is fixed.',
    },
];

const guarantees = [
    {
        icon: Fingerprint,
        title: 'Idempotent ingest',
        body: 'A unique index on (source, Idempotency-Key) decides, not a check-then-insert race. Proven with a multi-process test.',
    },
    {
        icon: RefreshCcw,
        title: 'At-least-once delivery',
        body: 'The database row is the source of truth for retries; queued jobs are the fast path and a sweep recovers anything lost.',
    },
    {
        icon: KeyRound,
        title: 'Signed, with rotation',
        body: 'Receivers verify t=…,v1=… with a timestamp window. During a rotation both secrets sign, so nothing is dropped.',
    },
    {
        icon: ShieldCheck,
        title: 'SSRF-safe',
        body: 'Private and reserved addresses are refused on save and again at send time, with the connection pinned to the checked IP.',
    },
    {
        icon: Zap,
        title: 'Kind to receivers',
        body: 'Per-endpoint rate limiting, and a breaker that parks a failing endpoint instead of hammering it.',
    },
    {
        icon: RotateCcw,
        title: 'Observable and replayable',
        body: 'Endpoint health, a filterable delivery log, the full attempt timeline, and one-click replay.',
    },
];
</script>

<template>
    <Head title="Reliable webhook delivery" />

    <div class="min-h-screen bg-background text-foreground">
        <header
            class="mx-auto flex max-w-6xl items-center justify-between px-6 py-5"
        >
            <div class="flex items-center gap-2 font-semibold">
                <AppLogoIcon class="size-6 fill-current" />
                Webhook Relay
            </div>
            <Button as-child size="sm">
                <Link :href="signedIn ? dashboard() : login()">
                    {{ signedIn ? 'Dashboard' : 'Log in' }}
                </Link>
            </Button>
        </header>

        <main class="mx-auto max-w-6xl px-6 pb-20">
            <section class="py-16 sm:py-24">
                <p class="text-sm font-medium text-muted-foreground">
                    Laravel · Horizon · MySQL · Redis
                </p>
                <h1
                    class="mt-3 max-w-3xl text-4xl font-semibold tracking-tight sm:text-5xl"
                >
                    Publish an event once. Deliver it to every subscriber,
                    reliably.
                </h1>
                <p class="mt-5 max-w-2xl text-lg text-muted-foreground">
                    An API-first relay between the apps that produce events and
                    the endpoints that need them. It handles signing, retries,
                    outages and replay, so the source app doesn't have to.
                </p>
                <div class="mt-8 flex flex-wrap gap-3">
                    <Button as-child size="lg">
                        <Link :href="signedIn ? dashboard() : login()">
                            {{ signedIn ? 'Open the dashboard' : 'Log in' }}
                            <ArrowRight />
                        </Link>
                    </Button>
                </div>

                <div
                    v-if="demoLogin && !signedIn"
                    class="mt-6 inline-flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border bg-muted/40 px-4 py-3 text-sm"
                    data-test="demo-login"
                >
                    <span class="font-medium">Demo login</span>
                    <code class="font-mono">{{ demoLogin.email }}</code>
                    <span class="text-muted-foreground">/</span>
                    <code class="font-mono">{{ demoLogin.password }}</code>
                </div>
            </section>

            <section aria-labelledby="flow-heading">
                <h2 id="flow-heading" class="text-xl font-semibold">
                    How an event travels
                </h2>
                <ol class="mt-6 grid gap-3 md:grid-cols-5">
                    <li
                        v-for="(step, i) in steps"
                        :key="step.title"
                        class="relative rounded-xl border p-4"
                    >
                        <p
                            class="text-xs font-medium text-muted-foreground tabular-nums"
                        >
                            {{ String(i + 1).padStart(2, '0') }}
                        </p>
                        <p class="mt-1 font-medium">{{ step.title }}</p>
                        <p class="mt-2 text-sm text-muted-foreground">
                            {{ step.body }}
                        </p>
                    </li>
                </ol>
            </section>

            <section aria-labelledby="guarantees-heading" class="mt-16">
                <h2 id="guarantees-heading" class="text-xl font-semibold">
                    What it guarantees
                </h2>
                <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div
                        v-for="item in guarantees"
                        :key="item.title"
                        class="rounded-xl border p-5"
                    >
                        <component
                            :is="item.icon"
                            class="size-5 text-muted-foreground"
                        />
                        <p class="mt-3 font-medium">{{ item.title }}</p>
                        <p class="mt-1 text-sm text-muted-foreground">
                            {{ item.body }}
                        </p>
                    </div>
                </div>
            </section>

            <section
                aria-labelledby="api-heading"
                class="mt-16 grid gap-6 lg:grid-cols-2"
            >
                <div>
                    <h2 id="api-heading" class="text-xl font-semibold">
                        Publishing
                    </h2>
                    <p class="mt-2 text-sm text-muted-foreground">
                        Sources authenticate with a token. The same
                        Idempotency-Key always returns the same event.
                    </p>
                    <pre
                        class="mt-4 overflow-x-auto rounded-xl border bg-muted/40 p-4 font-mono text-xs leading-relaxed"
                    ><code>curl -X POST /api/events \
  -H "Authorization: Bearer $SOURCE_TOKEN" \
  -H "Idempotency-Key: order-1042-paid" \
  -H "Content-Type: application/json" \
  -d '{"type":"invoice.paid","payload":{"invoice":"inv_42"}}'

HTTP/1.1 202 Accepted</code></pre>
                </div>
                <div>
                    <h2 class="text-xl font-semibold">Receiving</h2>
                    <p class="mt-2 text-sm text-muted-foreground">
                        Every delivery is signed. Laravel receivers verify it
                        with one middleware from the
                        <code class="font-mono">relay-signature</code>
                        package.
                    </p>
                    <pre
                        class="mt-4 overflow-x-auto rounded-xl border bg-muted/40 p-4 font-mono text-xs leading-relaxed"
                    ><code>X-Relay-Signature: t=1791472610,v1=5b1e…,v1=9f3c…

Route::post('webhooks/relay', ReceiveRelayWebhook::class)
    ->middleware('relay.signature');</code></pre>
                </div>
            </section>
        </main>

        <footer class="border-t">
            <div
                class="mx-auto max-w-6xl px-6 py-6 text-sm text-muted-foreground"
            >
                A portfolio project: delivery is at-least-once and unordered, by
                design. Receivers dedupe on the event id.
            </div>
        </footer>
    </div>
</template>
