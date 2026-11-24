<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import BrandWordmark from '@/components/BrandWordmark.vue';
import CursorGeometry from '@/components/auth/CursorGeometry.vue';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();

// The picture of the left panel. Swap the file here.
const artSrc = '/images/login-art.webp';
</script>

<template>
    <!-- The page is always dark: the picture needs it. The inner element gives the form an amber
         accent only here; the rest of the app keeps its own colours. -->
    <div class="dark bg-[#090605] text-foreground">
        <div
            class="relative isolate grid min-h-svh overflow-hidden [--primary-foreground:oklch(0.16_0.02_60)] [--primary:oklch(0.78_0.16_65)] [--ring:oklch(0.78_0.16_65)] lg:grid-cols-2"
        >
            <!-- The geometry fills the whole page; the picture dissolves into it. -->
            <CursorGeometry />

            <aside class="absolute inset-y-0 left-0 hidden w-[70%] lg:block">
                <div
                    class="absolute inset-0 -translate-x-[5%] [mask-image:linear-gradient(to_right,black_0%,black_52%,transparent_100%)] [-webkit-mask-image:linear-gradient(to_right,black_0%,black_52%,transparent_100%)]"
                >
                    <img
                        :src="artSrc"
                        alt=""
                        class="absolute inset-0 size-full object-cover object-[50%_3%]"
                        fetchpriority="high"
                    />
                    <div
                        class="absolute inset-0 bg-gradient-to-t from-[#090605]/90 via-transparent to-[#090605]/30"
                    />
                </div>

                <div
                    class="relative flex h-full max-w-[34rem] flex-col justify-between p-10 xl:p-14"
                >
                    <Link :href="home()" class="flex items-center gap-3.5">
                        <AppLogoIcon class="size-8 text-white" />
                        <BrandWordmark class="text-xl" />
                    </Link>

                    <p
                        class="font-brand text-4xl leading-[1.18] font-light tracking-tight text-white/90 xl:text-5xl"
                    >
                        Describe an image.<br />
                        See the price first.<br />
                        Watch it
                        <span class="font-medium text-amber-300">forged.</span>
                    </p>
                </div>
            </aside>

            <main
                class="relative z-10 flex items-center justify-center p-6 md:p-10 lg:col-start-2"
            >
                <div
                    class="relative w-full max-w-sm rounded-2xl border border-white/10 bg-black/40 p-8 shadow-2xl backdrop-blur-md"
                >
                    <div class="flex flex-col gap-8">
                        <div class="flex flex-col gap-4">
                            <Link
                                :href="home()"
                                class="flex items-center gap-2 lg:hidden"
                            >
                                <AppLogoIcon
                                    class="size-7 fill-current text-white"
                                />
                                <BrandWordmark class="text-xl" />
                            </Link>
                            <div class="space-y-2">
                                <h1 class="text-xl font-medium">{{ title }}</h1>
                                <p class="text-sm text-muted-foreground">
                                    {{ description }}
                                </p>
                            </div>
                        </div>
                        <slot />
                    </div>
                </div>
            </main>
        </div>
    </div>
</template>
