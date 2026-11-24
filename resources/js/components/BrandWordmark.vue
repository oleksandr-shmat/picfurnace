<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * The product name as a wordmark: capitals with wide spacing, "Pic" quieter than "Furnace".
 * It takes the text colour of where it is, so it works on the dark sign-in page and in the app.
 * A name that is not PicFurnace (it comes from the app config) is shown plainly.
 */
const name = usePage().props.name as string;
const parts = computed(() => /^(Pic)(Furnace)$/.exec(name));
</script>

<template>
    <span class="font-brand text-[0.8em] tracking-[0.3em] uppercase">
        <template v-if="parts">
            <span class="font-medium text-foreground/60">{{ parts[1] }}</span>
            <span class="font-bold text-foreground">{{ parts[2] }}</span>
        </template>
        <span v-else class="font-bold">{{ name }}</span>
    </span>
</template>
