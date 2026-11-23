export type GenerationStatus =
    | 'pending'
    | 'queued'
    | 'processing'
    | 'succeeded'
    | 'failed';

export type Generation = {
    id: string;
    status: GenerationStatus;
    prompt: string;
    size: string;
    quality: string;
    referenceImages: string[];
    /** The price range the user was shown before submitting, as exact decimal strings. */
    estimate: { min: string; expected: string; max: string };
    /** What the user was charged, once the generation is finished. */
    charged: string | null;
    failureMessage: string | null;
    cancelled: boolean;
    canCancel: boolean;
    /** Null unless succeeded. `url` is null when the image is gone. */
    image: { url: string | null; availableUntil: string | null } | null;
    createdAt: string;
};

export type FormOptions = {
    qualities: string[];
    sizes: string[];
    defaultQuality: string;
    defaultSize: string;
    maxPromptCharacters: number;
    maxReferenceImages: number;
    maxUnfinishedGenerations: number;
    markupPercent: number;
};

export type PriceRange = {
    min: string;
    expected: string;
    max: string;
    markup_percent: number;
};

export function isFinished(generation: Generation): boolean {
    return generation.status === 'succeeded' || generation.status === 'failed';
}
