<script setup lang="ts">
import { Head, router, usePoll } from '@inertiajs/vue3';
import { TriangleAlert } from '@lucide/vue';
import { computed, watch } from 'vue';
import CreditPanel from '@/components/generations/CreditPanel.vue';
import GenerationCard from '@/components/generations/GenerationCard.vue';
import GenerationForm from '@/components/generations/GenerationForm.vue';
import Heading from '@/components/Heading.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { isFinished } from '@/types/generations';
import type { FormOptions, Generation } from '@/types/generations';

const MORE_PER_CLICK = 20;

const props = defineProps<{
    generations: Generation[];
    hasMore: boolean;
    imageRetentionDays: number;
    /** What the user can spend, as an exact decimal string. Negative after an overrun. */
    balance: string;
    /** Whole dollars of each credit pack the user can add. */
    creditPacks: number[];
    /** Null when Pixelforge could not be asked for its pricing. */
    options: FormOptions | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Studio', href: dashboard() }],
    },
});

const unfinishedCount = computed(
    () =>
        props.generations.filter((generation) => !isFinished(generation))
            .length,
);

// The page follows the progress of the generations by reading our own database every few
// seconds, only while something is unfinished. Pixelforge is not asked: its request limit is
// shared by all users, and its webhooks already update our database.
const poll = usePoll(
    3000,
    { only: ['generations', 'hasMore', 'balance'] },
    { autoStart: false },
);

watch(unfinishedCount, (count) => (count > 0 ? poll.start() : poll.stop()), {
    immediate: true,
});

function showMore() {
    router.get(
        dashboard.url({
            query: { limit: props.generations.length + MORE_PER_CLICK },
        }),
        {},
        {
            only: ['generations', 'hasMore', 'balance'],
            preserveScroll: true,
            preserveState: true,
            replace: true,
        },
    );
}

function retryOptions() {
    router.reload({ only: ['options'] });
}
</script>

<template>
    <Head title="Studio" />

    <div class="mx-auto flex w-full max-w-6xl flex-col gap-8 p-4">
        <section class="space-y-4">
            <Heading
                title="Studio"
                description="Describe an image and see what it will cost before you send it."
            />

            <CreditPanel :balance="balance" :packs="creditPacks" />

            <GenerationForm
                v-if="options"
                :options="options"
                :balance="balance"
                :unfinished-count="unfinishedCount"
            />

            <Alert v-else variant="destructive">
                <TriangleAlert />
                <AlertTitle>Prices are not available right now</AlertTitle>
                <AlertDescription>
                    <p>
                        The image service did not answer, so the price of a
                        generation cannot be shown. Nothing has been charged.
                    </p>
                    <Button
                        class="mt-2"
                        size="sm"
                        variant="outline"
                        @click="retryOptions"
                    >
                        Try again
                    </Button>
                </AlertDescription>
            </Alert>
        </section>

        <section class="space-y-4">
            <h2 class="text-lg font-semibold">Your generations</h2>

            <p
                v-if="generations.length === 0"
                class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground"
            >
                Nothing here yet. Describe an image above and press Generate.
            </p>

            <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <GenerationCard
                    v-for="generation in generations"
                    :key="generation.id"
                    :generation="generation"
                    :image-retention-days="imageRetentionDays"
                />
            </div>

            <div v-if="hasMore" class="flex justify-center">
                <Button variant="outline" @click="showMore">Show more</Button>
            </div>
        </section>
    </div>
</template>
