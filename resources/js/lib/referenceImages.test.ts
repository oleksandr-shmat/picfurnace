import { describe, expect, it } from 'vite-plus/test';
import { referenceProblems, usableReferences } from '@/lib/referenceImages';

describe('referenceProblems', () => {
    it('has no problem with empty rows, they are ignored', () => {
        expect(referenceProblems(['', '   '])).toEqual([null, null]);
    });

    it('accepts full http and https addresses, whatever the spaces around them', () => {
        expect(
            referenceProblems([
                'https://example.com/a.png',
                '  http://example.com/b.png  ',
            ]),
        ).toEqual([null, null]);
    });

    it('refuses anything that is not a full http or https address', () => {
        const problems = referenceProblems([
            'not a url',
            'example.com/a.png',
            'javascript:alert(1)',
            'ftp://example.com/a.png',
            'file:///etc/passwd',
        ]);

        expect(problems.every((problem) => problem !== null)).toBe(true);
    });

    it('flags only the second use of an address, also when spaces differ', () => {
        expect(
            referenceProblems([
                'https://example.com/a.png',
                'https://example.com/b.png',
                ' https://example.com/a.png ',
            ]),
        ).toEqual([null, null, 'This address is already used above.']);
    });
});

describe('usableReferences', () => {
    it('keeps the filled-in addresses without problems, trimmed, in order', () => {
        expect(
            usableReferences([
                ' https://example.com/a.png ',
                '',
                'broken',
                'https://example.com/b.png',
                'https://example.com/a.png',
            ]),
        ).toEqual(['https://example.com/a.png', 'https://example.com/b.png']);
    });

    it('is empty when there is nothing usable', () => {
        expect(usableReferences([])).toEqual([]);
        expect(usableReferences(['', 'nope'])).toEqual([]);
    });
});
