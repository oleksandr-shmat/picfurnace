<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Sparkles } from '@lucide/vue';
import { computed } from 'vue';
import { toast } from 'vue-sonner';
import GenerationController from '@/actions/App/Http/Controllers/GenerationController';
import InputError from '@/components/InputError.vue';
import PriceEstimate from '@/components/generations/PriceEstimate.vue';
import ReferenceImagesField from '@/components/generations/ReferenceImagesField.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useEstimate } from '@/composables/useEstimate';
import { qualityLabel, sizeLabel } from '@/lib/labels';
import { cannotAfford, formatUsdRounded } from '@/lib/money';
import { referenceProblems, usableReferences } from '@/lib/referenceImages';
import type { FormOptions } from '@/types/generations';

const props = defineProps<{
    options: FormOptions;
    /** How many of the user's generations are waiting or running. */
    unfinishedCount: number;
    /** What the user can spend, as an exact decimal string. */
    balance: string;
}>();

const form = useForm({
    prompt: '',
    quality: props.options.defaultQuality,
    size: props.options.defaultSize,
    reference_images: [] as string[],
});

// Counted in characters, not UTF-16 units, the way the server counts them.
const promptLength = computed(() => [...form.prompt.trim()].length);

const promptTooLong = computed(
    () => promptLength.value > props.options.maxPromptCharacters,
);

const { estimate, state: estimateState } = useEstimate(() => ({
    prompt: form.prompt.trim(),
    quality: form.quality,
    size: form.size,
    referenceImages: usableReferences(form.reference_images),
}));

const queueIsFull = computed(
    () => props.unfinishedCount >= props.options.maxUnfinishedGenerations,
);

// The server sets aside the maximum price before a generation starts, so that is what the
// balance must cover.
const notEnoughCredit = computed(
    () =>
        estimate.value !== null &&
        cannotAfford(props.balance, estimate.value.max),
);

const canSubmit = computed(
    () =>
        !form.processing &&
        !queueIsFull.value &&
        !notEnoughCredit.value &&
        promptLength.value > 0 &&
        !promptTooLong.value &&
        referenceProblems(form.reference_images).every(
            (problem) => problem === null,
        ) &&
        estimateState.value !== 'unavailable',
);

function submit() {
    form.transform((data) => ({
        ...data,
        prompt: data.prompt.trim(),
        reference_images: usableReferences(data.reference_images),
    })).post(GenerationController.store.url(), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('prompt', 'reference_images');
            toast.success('Added to your queue');
        },
    });
}
</script>

<template>
    <form
        class="grid gap-6 rounded-xl border bg-card p-5 text-card-foreground shadow-sm lg:grid-cols-[1fr_20rem]"
        @submit.prevent="submit"
    >
        <div class="space-y-5">
            <div class="space-y-2">
                <Label for="prompt">What should it draw?</Label>
                <Textarea
                    id="prompt"
                    v-model="form.prompt"
                    class="min-h-28"
                    placeholder="A lighthouse on a cliff at dawn, oil painting"
                    :aria-invalid="promptTooLong || !!form.errors.prompt"
                />
                <div class="flex items-start justify-between gap-4">
                    <InputError :message="form.errors.prompt" />
                    <p
                        class="ml-auto text-xs tabular-nums"
                        :class="
                            promptTooLong
                                ? 'text-danger-text'
                                : 'text-muted-foreground'
                        "
                    >
                        {{ promptLength }} / {{ options.maxPromptCharacters }}
                    </p>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="space-y-2">
                    <Label>Size</Label>
                    <Select v-model="form.size">
                        <SelectTrigger class="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="size in options.sizes"
                                :key="size"
                                :value="size"
                            >
                                {{ sizeLabel(size) }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.size" />
                </div>

                <div class="space-y-2">
                    <Label>Quality</Label>
                    <Select v-model="form.quality">
                        <SelectTrigger class="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="quality in options.qualities"
                                :key="quality"
                                :value="quality"
                            >
                                {{ qualityLabel(quality) }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.quality" />
                </div>
            </div>

            <ReferenceImagesField
                v-model="form.reference_images"
                :max="options.maxReferenceImages"
                :server-errors="form.errors"
            />
        </div>

        <div class="flex flex-col gap-4 lg:border-l lg:pl-6">
            <PriceEstimate
                :estimate="estimate"
                :state="estimateState"
                :markup-percent="options.markupPercent"
            />

            <Alert v-if="queueIsFull">
                <AlertDescription>
                    You have {{ unfinishedCount }} generations in progress. You
                    can start another as soon as one of them finishes.
                </AlertDescription>
            </Alert>

            <Alert v-if="notEnoughCredit && estimate">
                <AlertDescription>
                    Your credit is too low for this one. It can cost up to
                    {{ formatUsdRounded(estimate.max) }} and you have
                    {{ formatUsdRounded(balance) }}. Add credit above to
                    continue.
                </AlertDescription>
            </Alert>

            <Button type="submit" size="lg" :disabled="!canSubmit">
                <Spinner v-if="form.processing" />
                <Sparkles v-else />
                Generate
            </Button>

            <p class="text-xs text-muted-foreground">
                It joins the queue and starts as soon as there is a free slot.
                You can cancel it while it waits, but not once it has started.
                You pay only if it succeeds; if it fails, it is free.
            </p>
        </div>
    </form>
</template>
