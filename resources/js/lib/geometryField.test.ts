import { describe, expect, it } from 'vite-plus/test';
import { buildMesh, glow, seededRandom } from '@/lib/geometryField';

describe('seededRandom', () => {
    it('gives the same numbers for the same seed and others for another', () => {
        const first = seededRandom(1);
        const again = seededRandom(1);
        const other = seededRandom(2);

        const a = [first(), first(), first()];

        expect([again(), again(), again()]).toEqual(a);
        expect([other(), other(), other()]).not.toEqual(a);
    });

    it('stays within 0 and 1', () => {
        const random = seededRandom(42);

        for (let i = 0; i < 1000; i++) {
            const value = random();

            expect(value).toBeGreaterThanOrEqual(0);
            expect(value).toBeLessThan(1);
        }
    });
});

describe('buildMesh', () => {
    it('covers the whole area and a margin beyond it', () => {
        const mesh = buildMesh(800, 600, 80);
        const xs = mesh.vertices.map((vertex) => vertex.x);
        const ys = mesh.vertices.map((vertex) => vertex.y);

        expect(Math.min(...xs)).toBeLessThan(0);
        expect(Math.max(...xs)).toBeGreaterThan(800);
        expect(Math.min(...ys)).toBeLessThan(0);
        expect(Math.max(...ys)).toBeGreaterThan(600);
    });

    it('has two triangles per cell and a vertex per corner', () => {
        // 800 / 80 = 10 columns and 600 / 80 = 7.5 -> 8 rows, plus two for the margin
        const mesh = buildMesh(800, 600, 80);

        expect(mesh.triangles).toHaveLength(2 * 12 * 10);
        expect(mesh.vertices).toHaveLength(13 * 11);
    });

    it('only uses vertices that exist, three different ones per triangle', () => {
        const mesh = buildMesh(500, 400, 90);

        for (const triangle of mesh.triangles) {
            expect(new Set(triangle).size).toBe(3);

            for (const index of triangle) {
                expect(index).toBeGreaterThanOrEqual(0);
                expect(index).toBeLessThan(mesh.vertices.length);
            }
        }
    });

    it('is the same for the same seed, so a resize does not make it jump', () => {
        expect(buildMesh(400, 400, 80, 3)).toEqual(buildMesh(400, 400, 80, 3));
        expect(buildMesh(400, 400, 80, 3)).not.toEqual(
            buildMesh(400, 400, 80, 4),
        );
    });

    it('keeps every triangle small: no vertex is thrown far from its cell', () => {
        const mesh = buildMesh(600, 600, 100);

        for (const [a, b, c] of mesh.triangles) {
            for (const [from, to] of [
                [a, b],
                [b, c],
                [c, a],
            ]) {
                const distance = Math.hypot(
                    mesh.vertices[from].x - mesh.vertices[to].x,
                    mesh.vertices[from].y - mesh.vertices[to].y,
                );

                expect(distance).toBeLessThan(100 * 2);
            }
        }
    });
});

describe('glow', () => {
    it('is full at the light and gone at the radius and beyond', () => {
        expect(glow(0, 200)).toBe(1);
        expect(glow(200, 200)).toBe(0);
        expect(glow(500, 200)).toBe(0);
    });

    it('fades smoothly and never grows with distance', () => {
        let previous = 1;

        for (let distance = 0; distance <= 200; distance += 10) {
            const value = glow(distance, 200);

            expect(value).toBeLessThanOrEqual(previous);
            expect(value).toBeGreaterThanOrEqual(0);
            previous = value;
        }

        expect(glow(100, 200)).toBeCloseTo(0.5, 5);
    });

    it('is dark without a usable radius', () => {
        expect(glow(0, 0)).toBe(0);
        expect(glow(0, -5)).toBe(0);
    });
});
