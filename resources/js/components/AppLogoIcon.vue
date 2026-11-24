<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <!-- The PicFurnace mark: a shard of glass drawn in a thin line, cut into facets, one of them
         lit by the furnace. The outline follows the text colour, the lit facet is always amber. -->
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 40 40"
        fill="none"
        :class="className"
        v-bind="$attrs"
    >
        <path fill="#ff9a2e" d="M34 21 L19 37 L21 21 Z" />
        <path
            stroke="currentColor"
            stroke-width="1.4"
            stroke-linejoin="round"
            stroke-linecap="round"
            d="M21 3 L34 21 L19 37 L7 19 Z M21 3 L21 21 M7 19 L21 21 M34 21 L21 21 M19 37 L21 21"
        />
    </svg>
</template>
