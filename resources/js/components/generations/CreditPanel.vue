<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Wallet } from '@lucide/vue';
import { computed, ref } from 'vue';
import CreditPurchaseController from '@/actions/App/Http/Controllers/CreditPurchaseController';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatUsd, toNano } from '@/lib/money';

const props = defineProps<{
    /** Exact decimal string, negative after a generation cost more than was held. */
    balance: string;
    /** Whole dollars the user can add. */
    packs: number[];
}>();

const buying = ref<number | null>(null);

const isNegative = computed(() => toNano(props.balance) < 0n);

function buy(pack: number) {
    router.post(
        CreditPurchaseController.url(),
        { pack },
        {
            preserveScroll: true,
            onStart: () => (buying.value = pack),
            onFinish: () => (buying.value = null),
        },
    );
}
</script>

<template>
    <div
        class="flex flex-col gap-3 rounded-xl border bg-card p-4 text-card-foreground shadow-sm sm:flex-row sm:items-center sm:justify-between"
    >
        <div class="flex items-center gap-3">
            <Wallet class="size-5 text-primary-ink" />
            <div>
                <p class="text-sm text-muted-foreground">Your credit</p>
                <p
                    class="text-xl font-semibold tabular-nums"
                    :class="{ 'text-danger-text': isNegative }"
                >
                    {{ formatUsd(balance) }}
                </p>
            </div>
        </div>

        <div class="space-y-1.5 sm:text-right">
            <div class="flex flex-wrap gap-2 sm:justify-end">
                <Button
                    v-for="pack in packs"
                    :key="pack"
                    variant="outline"
                    size="sm"
                    :disabled="buying !== null"
                    @click="buy(pack)"
                >
                    <Spinner v-if="buying === pack" />
                    Add ${{ pack }}
                </Button>
            </div>
            <p class="text-xs text-muted-foreground">
                Demo only: no real payment is made.
            </p>
        </div>

        <p
            v-if="isNegative"
            class="text-sm text-muted-foreground sm:basis-full"
        >
            Your credit is below zero because the last generation cost more than
            was set aside for it. Add credit to start a new one.
        </p>
    </div>
</template>
