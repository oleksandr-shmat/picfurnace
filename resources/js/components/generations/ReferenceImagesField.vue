<script setup lang="ts">
import { Plus, X } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { referenceProblems } from '@/lib/referenceImages';

const addresses = defineModel<string[]>({ required: true });

const props = defineProps<{
    max: number;
    /** Errors the server reported, keyed like "reference_images" and "reference_images.0". */
    serverErrors: Record<string, string>;
}>();

// Bound instead of written in the template: vue-tsc mis-reads "//" inside a static attribute and
// then loses the variables of the enclosing v-for.
const examplePlaceholder = 'https://example.com/style.png';

const rows = computed(() => {
    const problems = referenceProblems(addresses.value);

    return addresses.value.map((address, index) => ({
        index,
        address,
        error:
            problems[index] ?? props.serverErrors[`reference_images.${index}`],
    }));
});

const canAdd = computed(() => addresses.value.length < props.max);

function change(index: number, address: string) {
    addresses.value = addresses.value.map((current, i) =>
        i === index ? address : current,
    );
}

function remove(index: number) {
    addresses.value = addresses.value.filter((_, i) => i !== index);
}

function add() {
    addresses.value = [...addresses.value, ''];
}
</script>

<template>
    <div class="space-y-2">
        <Label>Reference images (optional)</Label>
        <p class="text-xs text-muted-foreground">
            Links to pictures that guide the style. Each one adds to the price.
            Up to {{ max }}.
        </p>

        <div v-for="row in rows" :key="row.index" class="space-y-1">
            <div class="flex gap-2">
                <Input
                    :model-value="row.address"
                    type="url"
                    :placeholder="examplePlaceholder"
                    :aria-invalid="row.error !== undefined"
                    @update:model-value="change(row.index, String($event))"
                />
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    aria-label="Remove this reference image"
                    @click="remove(row.index)"
                >
                    <X />
                </Button>
            </div>
            <InputError :message="row.error" />
        </div>

        <InputError :message="serverErrors.reference_images" />

        <Button
            v-if="canAdd"
            type="button"
            variant="outline"
            size="sm"
            @click="add"
        >
            <Plus />
            Add a reference image
        </Button>
    </div>
</template>
