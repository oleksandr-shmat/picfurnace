<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { buildMesh, glow } from '@/lib/geometryField';
import type { Mesh } from '@/lib/geometryField';

/**
 * Decorative background: a field of glass-like triangles whose vertices drift slowly. A light
 * follows the cursor (or the finger): near it the triangles glow amber, their edges light up and
 * the vertices part a little. When nobody is moving a pointer the light wanders on its own, so the
 * page is never dead. With "reduce motion" it is drawn once and does not move.
 */

const CELL = 84;
const RADIUS = 270;
const PUSH = 20;
const DRIFT = 7;
const IDLE_AFTER_MS = 2500;

const AMBER = '255, 150, 50';
const TEAL = '45, 190, 185';

const canvas = ref<HTMLCanvasElement | null>(null);

let context: CanvasRenderingContext2D | null = null;
let mesh: Mesh = { vertices: [], triangles: [] };
let positionX = new Float32Array(0);
let positionY = new Float32Array(0);
let lit = new Float32Array(0);

let width = 0;
let height = 0;
let frameId = 0;
let observer: ResizeObserver | null = null;
let reduceMotion = false;

// Where the light is, where it is heading, and how strong it is (0 when the pointer left).
const light = { x: 0, y: 0 };
const target = { x: 0, y: 0 };
let strength = 0;
let strengthTarget = 0;
let lastPointerAt = Number.NEGATIVE_INFINITY;

function resize() {
    const element = canvas.value;

    if (!element) {
        return;
    }

    const box = element.getBoundingClientRect();
    const ratio = Math.min(window.devicePixelRatio || 1, 2);

    width = box.width;
    height = box.height;
    element.width = Math.round(width * ratio);
    element.height = Math.round(height * ratio);
    context = element.getContext('2d');
    context?.setTransform(ratio, 0, 0, ratio, 0, 0);

    mesh = buildMesh(width, height, CELL);
    positionX = new Float32Array(mesh.vertices.length);
    positionY = new Float32Array(mesh.vertices.length);
    lit = new Float32Array(mesh.vertices.length);

    light.x = target.x = width * 0.62;
    light.y = target.y = height * 0.4;

    if (reduceMotion) {
        strength = 0.6;
        render(0);
    }
}

function render(seconds: number) {
    if (!context) {
        return;
    }

    context.clearRect(0, 0, width, height);

    // A soft warm pool of light under the cursor.
    const pool = context.createRadialGradient(
        light.x,
        light.y,
        0,
        light.x,
        light.y,
        RADIUS * 1.5,
    );
    pool.addColorStop(0, `rgba(${AMBER}, ${0.14 * strength})`);
    pool.addColorStop(1, `rgba(${AMBER}, 0)`);
    context.fillStyle = pool;
    context.fillRect(0, 0, width, height);

    const vertices = mesh.vertices;

    for (let i = 0; i < vertices.length; i++) {
        const vertex = vertices[i];
        let x =
            vertex.x +
            (reduceMotion
                ? 0
                : Math.sin(seconds * vertex.speed + vertex.phase) * DRIFT);
        let y =
            vertex.y +
            (reduceMotion
                ? 0
                : Math.cos(seconds * vertex.speed * 0.8 + vertex.phase) *
                  DRIFT);

        const dx = x - light.x;
        const dy = y - light.y;
        const distance = Math.hypot(dx, dy);
        const glowing = glow(distance, RADIUS) * strength;

        // The vertices part around the light.
        if (distance > 0.001) {
            x += (dx / distance) * glowing * PUSH;
            y += (dy / distance) * glowing * PUSH;
        }

        positionX[i] = x;
        positionY[i] = y;
        lit[i] = glowing;
    }

    context.lineWidth = 1;
    context.lineJoin = 'round';

    mesh.triangles.forEach(([a, b, c], index) => {
        const glowing = (lit[a] + lit[b] + lit[c]) / 3;
        // A few triangles catch a cool teal glint, like the edges of the glass in the picture.
        const colour = index % 19 === 0 ? TEAL : AMBER;

        context!.beginPath();
        context!.moveTo(positionX[a], positionY[a]);
        context!.lineTo(positionX[b], positionY[b]);
        context!.lineTo(positionX[c], positionY[c]);
        context!.closePath();

        if (glowing > 0.02) {
            context!.fillStyle = `rgba(${colour}, ${glowing * 0.2})`;
            context!.fill();
        }

        context!.strokeStyle = `rgba(${colour}, ${0.05 + glowing * 0.55})`;
        context!.stroke();
    });

    for (let i = 0; i < lit.length; i++) {
        if (lit[i] > 0.2) {
            context.fillStyle = `rgba(${AMBER}, ${lit[i]})`;
            context.beginPath();
            context.arc(positionX[i], positionY[i], 1 + lit[i] * 2, 0, 7);
            context.fill();
        }
    }
}

function frame(now: number) {
    if (now - lastPointerAt > IDLE_AFTER_MS) {
        // Nobody is pointing: the light wanders on its own.
        const seconds = now / 1000;
        target.x = width * (0.5 + 0.32 * Math.sin(seconds * 0.35));
        target.y = height * (0.45 + 0.28 * Math.sin(seconds * 0.27 + 1));
        strengthTarget = 0.55;
    }

    light.x += (target.x - light.x) * 0.12;
    light.y += (target.y - light.y) * 0.12;
    strength += (strengthTarget - strength) * 0.08;

    render(now / 1000);
    frameId = requestAnimationFrame(frame);
}

function start() {
    if (!reduceMotion && frameId === 0) {
        frameId = requestAnimationFrame(frame);
    }
}

function stop() {
    cancelAnimationFrame(frameId);
    frameId = 0;
}

function onPointerMove(event: PointerEvent) {
    const box = canvas.value?.getBoundingClientRect();

    if (!box) {
        return;
    }

    target.x = event.clientX - box.left;
    target.y = event.clientY - box.top;
    strengthTarget = 1;
    lastPointerAt = performance.now();
}

function onPointerLeave() {
    strengthTarget = 0;
    lastPointerAt = performance.now();
}

function onVisibilityChange() {
    if (document.hidden) {
        stop();
    } else {
        start();
    }
}

onMounted(() => {
    reduceMotion = window.matchMedia(
        '(prefers-reduced-motion: reduce)',
    ).matches;

    resize();
    observer = new ResizeObserver(resize);
    observer.observe(canvas.value!);

    if (!reduceMotion) {
        window.addEventListener('pointermove', onPointerMove);
        document.addEventListener('mouseleave', onPointerLeave);
        document.addEventListener('visibilitychange', onVisibilityChange);
        start();
    }
});

onBeforeUnmount(() => {
    stop();
    observer?.disconnect();
    window.removeEventListener('pointermove', onPointerMove);
    document.removeEventListener('mouseleave', onPointerLeave);
    document.removeEventListener('visibilitychange', onVisibilityChange);
});
</script>

<template>
    <canvas
        ref="canvas"
        class="absolute inset-0 size-full"
        aria-hidden="true"
    />
</template>
