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
 * True when the balance cannot cover the price: the server holds the maximum price of the
 * estimate before a generation starts.
 */
export function cannotAfford(balance: string, price: string): boolean {
    return toNano(balance) < toNano(price);
}
