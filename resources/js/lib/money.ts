/**
 * Shows an exact amount from the server ("0.017287500") without the trailing zeros that carry no
 * information, but always with at least four decimals ("$0.0170"). Nothing is rounded.
 * A negative amount keeps its sign in front of the dollar sign ("-$2.5000").
 */
export function formatUsd(amount: string): string {
    const negative = amount.startsWith('-');
    const [whole, fraction = ''] = amount.replace('-', '').split('.');
    const decimals = fraction.replace(/0+$/, '').padEnd(4, '0');

    return `${negative ? '-' : ''}$${whole}.${decimals}`;
}

/**
 * An exact amount with all nine decimals ("$0.017287500"), the way Pixelforge reports it: a column
 * of them lines up and can be compared with `GET /v1/usage` digit by digit.
 */
export function formatUsdFull(amount: string): string {
    const nano = toNano(amount);
    const size = nano < 0n ? -nano : nano;
    const whole = size / 1_000_000_000n;
    const fraction = String(size % 1_000_000_000n).padStart(9, '0');

    return `${nano < 0n ? '-' : ''}$${whole}.${fraction}`;
}

/**
 * An exact amount from the server as whole nanodollars, so two amounts can be compared without
 * floating point.
 */
export function toNano(amount: string): bigint {
    const negative = amount.startsWith('-');
    const [whole, fraction = ''] = amount.replace('-', '').split('.');
    const nano =
        BigInt(whole) * 1_000_000_000n + BigInt(fraction.padEnd(9, '0'));

    return negative ? -nano : nano;
}

/**
 * A short form of an amount for where the exact one is too much: rounded half up to `decimals`
 * decimals ("$0.0179"). An amount that is not zero but would round to zero is shown as "<$0.0001"
 * instead of "$0.0000". Use `formatUsd` wherever the exact amount matters.
 */
export function formatUsdRounded(amount: string, decimals = 4): string {
    const nano = toNano(amount);
    const negative = nano < 0n;
    const size = nano < 0n ? -nano : nano;
    const unit = 10n ** BigInt(9 - decimals);
    const rounded = (size + unit / 2n) / unit;

    if (rounded === 0n && size > 0n) {
        return `<$0.${'0'.repeat(decimals - 1)}1`;
    }

    const scale = 10n ** BigInt(decimals);
    const fraction = String(rounded % scale).padStart(decimals, '0');

    return `${negative ? '-' : ''}$${rounded / scale}.${fraction}`;
}

/**
 * True when the balance cannot cover the price: the server holds the maximum price of the
 * estimate before a generation starts.
 */
export function cannotAfford(balance: string, price: string): boolean {
    return toNano(balance) < toNano(price);
}
