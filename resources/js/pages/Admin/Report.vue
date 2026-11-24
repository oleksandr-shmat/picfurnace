<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { TriangleAlert } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { formatUsd } from '@/lib/money';
import { report } from '@/routes/admin';

type Line = {
    key: string;
    /** A readable name when the key is not enough (users). */
    label: string | null;
    generations: number;
    /** Exact decimal strings. */
    charged: string;
    providerCost: string;
    profit: string;
};

defineProps<{
    /** Newest day first. */
    days: Line[];
    /** Biggest spender first. */
    users: Line[];
    total: Line;
    /** False when the ledger and the generations hold different charges. */
    ledgerAgrees: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Report', href: report() }],
    },
});
</script>

<template>
    <Head title="Report" />

    <div class="mx-auto flex w-full max-w-6xl flex-col gap-8 p-4">
        <section class="space-y-4">
            <Heading
                title="Report"
                description="What users were charged, what Pixelforge billed us and what we kept. Succeeded generations only: a failed one is free."
            />

            <Alert v-if="!ledgerAgrees" variant="destructive">
                <TriangleAlert />
                <AlertTitle>The ledger and the generations disagree</AlertTitle>
                <AlertDescription>
                    The charges in the credit ledger are not the same as the
                    charges written on the generations. Money was moved around
                    the ledger and needs a look.
                </AlertDescription>
            </Alert>

            <dl class="grid gap-4 sm:grid-cols-4">
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <dt class="text-sm text-muted-foreground">Generations</dt>
                    <dd class="text-xl font-semibold tabular-nums">
                        {{ total.generations }}
                    </dd>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <dt class="text-sm text-muted-foreground">Charged</dt>
                    <dd class="text-xl font-semibold tabular-nums">
                        {{ formatUsd(total.charged) }}
                    </dd>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <dt class="text-sm text-muted-foreground">
                        Pixelforge cost
                    </dt>
                    <dd class="text-xl font-semibold tabular-nums">
                        {{ formatUsd(total.providerCost) }}
                    </dd>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <dt class="text-sm text-muted-foreground">Profit</dt>
                    <dd class="text-xl font-semibold tabular-nums">
                        {{ formatUsd(total.profit) }}
                    </dd>
                </div>
            </dl>
        </section>

        <section class="space-y-3">
            <h2 class="text-lg font-semibold">Per day (UTC)</h2>
            <p class="text-sm text-muted-foreground">
                A generation counts on the day it completed. The Pixelforge cost
                of a day should match what
                <code>GET /v1/usage</code> reports for that day.
            </p>

            <p
                v-if="days.length === 0"
                class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground"
            >
                No generation has succeeded yet.
            </p>

            <div
                v-else
                class="overflow-x-auto rounded-xl border bg-card shadow-sm"
            >
                <table class="w-full text-sm tabular-nums">
                    <thead class="border-b text-left text-muted-foreground">
                        <tr>
                            <th class="p-3 font-medium">Day</th>
                            <th class="p-3 text-right font-medium">
                                Generations
                            </th>
                            <th class="p-3 text-right font-medium">Charged</th>
                            <th class="p-3 text-right font-medium">
                                Pixelforge cost
                            </th>
                            <th class="p-3 text-right font-medium">Profit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="day in days"
                            :key="day.key"
                            class="border-b last:border-0"
                        >
                            <td class="p-3">{{ day.key }}</td>
                            <td class="p-3 text-right">
                                {{ day.generations }}
                            </td>
                            <td class="p-3 text-right">
                                {{ formatUsd(day.charged) }}
                            </td>
                            <td class="p-3 text-right">
                                {{ formatUsd(day.providerCost) }}
                            </td>
                            <td class="p-3 text-right">
                                {{ formatUsd(day.profit) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section v-if="users.length > 0" class="space-y-3">
            <h2 class="text-lg font-semibold">Per user</h2>

            <div class="overflow-x-auto rounded-xl border bg-card shadow-sm">
                <table class="w-full text-sm tabular-nums">
                    <thead class="border-b text-left text-muted-foreground">
                        <tr>
                            <th class="p-3 font-medium">User</th>
                            <th class="p-3 text-right font-medium">
                                Generations
                            </th>
                            <th class="p-3 text-right font-medium">Charged</th>
                            <th class="p-3 text-right font-medium">
                                Pixelforge cost
                            </th>
                            <th class="p-3 text-right font-medium">Profit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="user in users"
                            :key="user.key"
                            class="border-b last:border-0"
                        >
                            <td class="p-3">{{ user.label }}</td>
                            <td class="p-3 text-right">
                                {{ user.generations }}
                            </td>
                            <td class="p-3 text-right">
                                {{ formatUsd(user.charged) }}
                            </td>
                            <td class="p-3 text-right">
                                {{ formatUsd(user.providerCost) }}
                            </td>
                            <td class="p-3 text-right">
                                {{ formatUsd(user.profit) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
