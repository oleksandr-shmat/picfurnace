import { useHttp } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { ref, watch } from 'vue';
import EstimateController from '@/actions/App/Http/Controllers/EstimateController';
import type { PriceRange } from '@/types/generations';

export type EstimateInput = {
    prompt: string;
    quality: string;
    size: string;
    referenceImages: string[];
};

/**
 * The price range for what is typed in the form.
 *
 * The server knows the current Pixelforge pricing and our markup, so it does the maths. This asks
 * shortly after the user stops typing and cancels a request that a newer one has overtaken.
 */
export function useEstimate(input: () => EstimateInput) {
    const estimate = ref<PriceRange | null>(null);
    const state = ref<'loading' | 'ready' | 'unavailable'>('loading');

    const http = useHttp({
        prompt: '',
        quality: '',
        size: '',
        reference_images: [] as string[],
    });

    const request = useDebounceFn(() => {
        const current = input();

        http.cancel();
        http.prompt = current.prompt;
        http.quality = current.quality;
        http.size = current.size;
        http.reference_images = current.referenceImages;

        void http.post(EstimateController.url(), {
            onSuccess: (data: unknown) => {
                estimate.value = data as PriceRange;
                state.value = 'ready';
            },
            onError: () => {
                state.value = 'unavailable';
            },
            onHttpException: () => {
                state.value = 'unavailable';
            },
            onNetworkError: () => {
                state.value = 'unavailable';
            },
        });
    }, 400);

    watch(
        input,
        () => {
            state.value = 'loading';

            // Server-side rendering has no XMLHttpRequest and no user typing: only the browser asks.
            if (!import.meta.env.SSR) {
                void request();
            }
        },
        { deep: true, immediate: true },
    );

    return { estimate, state };
}
