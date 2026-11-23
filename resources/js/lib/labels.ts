/** "1536x1024" as "1536 × 1024 (landscape)". */
export function sizeLabel(size: string): string {
    const [width, height] = size.split('x').map(Number);
    const shape =
        width > height ? 'landscape' : width < height ? 'portrait' : 'square';

    return `${width} × ${height} (${shape})`;
}

/** "medium" as "Medium". */
export function qualityLabel(quality: string): string {
    return quality.charAt(0).toUpperCase() + quality.slice(1);
}
