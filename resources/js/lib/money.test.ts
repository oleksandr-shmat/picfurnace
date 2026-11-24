import { expect, it } from 'vite-plus/test';
import { cannotAfford, formatUsd, toNano } from '@/lib/money';

it('drops zeros that carry no information but keeps at least four decimals', () => {
    expect(formatUsd('0.017000000')).toBe('$0.0170');
    expect(formatUsd('12.500000000')).toBe('$12.5000');
    expect(formatUsd('1.000000000')).toBe('$1.0000');
    expect(formatUsd('0.000000000')).toBe('$0.0000');
});

it('keeps every decimal that matters, down to a nanodollar', () => {
    expect(formatUsd('0.017287500')).toBe('$0.0172875');
    expect(formatUsd('1.297507500')).toBe('$1.2975075');
    expect(formatUsd('0.000000001')).toBe('$0.000000001');
    expect(formatUsd('0.373875000')).toBe('$0.373875');
});

it('also reads an amount without decimals', () => {
    expect(formatUsd('3')).toBe('$3.0000');
});

it('never changes the amount: nothing is rounded', () => {
    // nine-decimal strings from nanodollar counts, as the server sends them
    for (const nano of [
        1, 7, 999, 1_000_001, 17_287_500, 123_456_789, 9_999_999_999,
    ]) {
        const whole = Math.trunc(nano / 1e9);
        const fraction = String(nano % 1e9).padStart(9, '0');
        const exact = `${whole}.${fraction}`;

        expect(Number(formatUsd(exact).slice(1))).toBe(Number(exact));
    }
});

it('keeps the sign of a negative amount', () => {
    expect(formatUsd('-2.500000000')).toBe('-$2.5000');
    expect(formatUsd('-0.000000001')).toBe('-$0.000000001');
});

it('counts an amount in whole nanodollars', () => {
    expect(toNano('5.000000000')).toBe(5_000_000_000n);
    expect(toNano('0.017287500')).toBe(17_287_500n);
    expect(toNano('0.000000001')).toBe(1n);
    expect(toNano('3')).toBe(3_000_000_000n);
    expect(toNano('-2.500000000')).toBe(-2_500_000_000n);
    expect(toNano('-0.000000001')).toBe(-1n);
});

it('says the balance cannot cover a price only when it is below it', () => {
    expect(cannotAfford('0.467343749', '0.467343750')).toBe(true);
    expect(cannotAfford('0.467343750', '0.467343750')).toBe(false);
    expect(cannotAfford('5.000000000', '0.467343750')).toBe(false);
    expect(cannotAfford('0.000000000', '0.000000001')).toBe(true);
    expect(cannotAfford('-1.000000000', '0.000000001')).toBe(true);
});
