<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import BrandWordmark from '@/components/BrandWordmark.vue';
</script>

<template>
    <!-- The mark sits in its own box: the sidebar button shrinks and left-aligns an icon that is
         its direct child, and the box keeps the mark centred when the sidebar is collapsed. -->
    <span class="flex size-8 shrink-0 items-center justify-center">
        <AppLogoIcon class="size-6 text-foreground" />
    </span>
    <BrandWordmark
        class="ml-1 truncate text-sm group-data-[collapsible=icon]:hidden"
    />
</template>
