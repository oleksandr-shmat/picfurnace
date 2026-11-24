<script setup lang="ts">
import { ref, watch } from 'vue';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { PERIODS } from '@/lib/reportQuery';
import type { ReportChange } from '@/lib/reportQuery';
import type { ReportFilters, ReportRange, UserOption } from '@/types/report';

const props = defineProps<{
    filters: ReportFilters;
    range: ReportRange;
    userOptions: UserOption[];
}>();

const emit = defineEmits<{ change: [change: ReportChange] }>();

// The dates of a custom period are edited here and sent when both make sense.
const from = ref(props.filters.from ?? props.range.from ?? '');
const to = ref(props.filters.to ?? props.range.to ?? '');

watch(
    () => [props.filters.from, props.filters.to],
    ([newFrom, newTo]) => {
        from.value = newFrom ?? from.value;
        to.value = newTo ?? to.value;
    },
);

function choosePeriod(period: string) {
    if (period === 'custom') {
        emit('change', {
            period,
            from: from.value || props.range.from,
            to: to.value || props.range.to,
        });

        return;
    }

    emit('change', { period, from: null, to: null });
}

function changeDates() {
    if (from.value && to.value && from.value <= to.value) {
        emit('change', { period: 'custom', from: from.value, to: to.value });
    }
}

function chooseUser(value: unknown) {
    emit('change', { user: value === 'all' ? null : Number(value) });
}
</script>

<template>
    <div class="flex flex-wrap items-center gap-3">
        <div
            class="inline-flex flex-wrap gap-1 rounded-lg border bg-muted p-1"
            role="radiogroup"
            aria-label="Period"
        >
            <button
                v-for="period in PERIODS"
                :key="period.value"
                type="button"
                role="radio"
                :aria-checked="filters.period === period.value"
                :class="[
                    'rounded-md px-3 py-1.5 text-sm transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                    filters.period === period.value
                        ? 'bg-card font-medium text-foreground shadow-sm'
                        : 'text-muted-foreground hover:text-foreground',
                ]"
                @click="choosePeriod(period.value)"
            >
                {{ period.label }}
            </button>
        </div>

        <div v-if="filters.period === 'custom'" class="flex items-center gap-2">
            <Input
                v-model="from"
                type="date"
                class="w-40"
                :max="to || undefined"
                aria-label="From (UTC)"
                @change="changeDates"
            />
            <span class="text-sm text-muted-foreground">to</span>
            <Input
                v-model="to"
                type="date"
                class="w-40"
                :min="from || undefined"
                aria-label="To (UTC)"
                @change="changeDates"
            />
        </div>

        <Select
            :model-value="filters.user === null ? 'all' : String(filters.user)"
            @update:model-value="chooseUser"
        >
            <SelectTrigger class="w-64" aria-label="User">
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value="all">All users</SelectItem>
                <SelectItem
                    v-for="user in userOptions"
                    :key="user.id"
                    :value="String(user.id)"
                >
                    {{ user.label }}
                </SelectItem>
            </SelectContent>
        </Select>
    </div>
</template>
