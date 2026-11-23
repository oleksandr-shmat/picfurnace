import { expect, it } from 'vite-plus/test';
import { isFinished } from '@/types/generations';
import type { Generation, GenerationStatus } from '@/types/generations';

function generationWith(status: GenerationStatus): Generation {
    return { status } as Generation;
}

it('is finished only when it succeeded or failed: the page keeps polling for the rest', () => {
    const finished = (
        ['pending', 'queued', 'processing', 'succeeded', 'failed'] as const
    ).map((status) => [status, isFinished(generationWith(status))]);

    expect(finished).toEqual([
        ['pending', false],
        ['queued', false],
        ['processing', false],
        ['succeeded', true],
        ['failed', true],
    ]);
});
