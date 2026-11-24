/**
 * The maths behind the sign-in page background: a field of triangles (a low-poly mesh) that
 * covers a rectangle, and how strongly a point is lit by a light at some distance. Kept apart from
 * the canvas code so it can be tested.
 */

export type Vertex = {
    /** Resting position. */
    x: number;
    y: number;
    /** Where in its slow drift the vertex starts and how fast it moves, so they do not move together. */
    phase: number;
    speed: number;
};

export type Mesh = {
    vertices: Vertex[];
    /** Indexes into `vertices`. */
    triangles: [number, number, number][];
};

/** A small repeatable random generator (mulberry32): the same seed gives the same mesh. */
export function seededRandom(seed: number): () => number {
    let state = seed >>> 0;

    return () => {
        state = (state + 0x6d2b79f5) >>> 0;
        let t = state;
        t = Math.imul(t ^ (t >>> 15), t | 1);
        t ^= t + Math.imul(t ^ (t >>> 7), t | 61);

        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

/**
 * A mesh of roughly `cell` sized triangles that covers `width` x `height` and a little beyond, so
 * the drifting vertices never show an edge. Vertices are jittered off the grid to look hand-cut.
 */
export function buildMesh(
    width: number,
    height: number,
    cell: number,
    seed = 7,
): Mesh {
    const random = seededRandom(seed);
    const columns = Math.ceil(width / cell) + 2;
    const rows = Math.ceil(height / cell) + 2;
    const vertices: Vertex[] = [];

    for (let row = 0; row <= rows; row++) {
        for (let column = 0; column <= columns; column++) {
            vertices.push({
                x: (column - 1 + (random() - 0.5) * 0.6) * cell,
                y: (row - 1 + (random() - 0.5) * 0.6) * cell,
                phase: random() * Math.PI * 2,
                speed: 0.25 + random() * 0.35,
            });
        }
    }

    const at = (column: number, row: number) => row * (columns + 1) + column;
    const triangles: Mesh['triangles'][number][] = [];

    for (let row = 0; row < rows; row++) {
        for (let column = 0; column < columns; column++) {
            const topLeft = at(column, row);
            const topRight = at(column + 1, row);
            const bottomLeft = at(column, row + 1);
            const bottomRight = at(column + 1, row + 1);

            // The diagonal alternates, so the cuts do not line up into long straight seams.
            if ((column + row) % 2 === 0) {
                triangles.push([topLeft, topRight, bottomRight]);
                triangles.push([topLeft, bottomRight, bottomLeft]);
            } else {
                triangles.push([topLeft, topRight, bottomLeft]);
                triangles.push([topRight, bottomRight, bottomLeft]);
            }
        }
    }

    return { vertices, triangles };
}

/**
 * How lit a point is by a light `distance` away: 1 right at the light, easing to 0 at `radius`
 * and beyond.
 */
export function glow(distance: number, radius: number): number {
    if (radius <= 0 || distance >= radius) {
        return 0;
    }

    const closeness = 1 - Math.max(distance, 0) / radius;

    return closeness * closeness * (3 - 2 * closeness);
}
