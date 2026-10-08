<script setup lang="ts">
defineProps<{ value: string }>();
</script>

<template>
    <pre
        class="max-h-96 overflow-auto rounded-md border bg-muted/40 p-3 font-mono text-xs leading-relaxed break-all whitespace-pre-wrap"
    ><code>{{ value }}</code></pre>
</template>
