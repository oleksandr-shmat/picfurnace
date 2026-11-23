import { expect, it } from 'vite-plus/test';
import { qualityLabel, sizeLabel } from '@/lib/labels';

it('names the shape of a size', () => {
    expect(sizeLabel('1024x1024')).toBe('1024 × 1024 (square)');
    expect(sizeLabel('1536x1024')).toBe('1536 × 1024 (landscape)');
    expect(sizeLabel('1024x1536')).toBe('1024 × 1536 (portrait)');
});

it('starts a quality with a capital letter', () => {
    expect(qualityLabel('low')).toBe('Low');
    expect(qualityLabel('medium')).toBe('Medium');
});
