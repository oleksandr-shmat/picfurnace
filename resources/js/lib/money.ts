/**
 * Shows an exact amount from the server ("0.017287500") without the trailing zeros that carry no
 * information, but always with at least four decimals ("$0.0170"). Nothing is rounded.
 */
export function formatUsd(amount: string): string {
    const [whole, fraction = ''] = amount.split('.');
    const decimals = fraction.replace(/0+$/, '').padEnd(4, '0');

    return `$${whole}.${decimals}`;
}
