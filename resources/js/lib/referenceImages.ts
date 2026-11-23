function isHttpUrl(value: string): boolean {
    try {
        const url = new URL(value);

        return url.protocol === 'http:' || url.protocol === 'https:';
    } catch {
        return false;
    }
}

/**
 * What is wrong with each reference address, or null when it is fine. Empty rows are ignored, as
 * they are when the form is sent.
 */
export function referenceProblems(addresses: string[]): (string | null)[] {
    return addresses.map((value, index) => {
        const address = value.trim();

        if (address === '') {
            return null;
        }

        if (!isHttpUrl(address)) {
            return 'Enter a full address starting with http:// or https://';
        }

        const usedBefore = addresses
            .slice(0, index)
            .some((other) => other.trim() === address);

        return usedBefore ? 'This address is already used above.' : null;
    });
}

/** The addresses that will be sent: filled in and without problems. */
export function usableReferences(addresses: string[]): string[] {
    const problems = referenceProblems(addresses);

    return addresses
        .map((value) => value.trim())
        .filter((value, index) => value !== '' && problems[index] === null);
}
