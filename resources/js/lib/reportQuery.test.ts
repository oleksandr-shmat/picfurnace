import { describe, expect, it } from 'vite-plus/test';
import {
    nextSort,
    periodLabel,
    previousPeriodLabel,
    reportQuery,
    rowRange,
} from '@/lib/reportQuery';
import type { ReportFilters } from '@/types/report';

const defaults: ReportFilters = {
    period: '30d',
    from: null,
    to: null,
    user: null,
    search: null,
    daysSort: 'day',
    daysDir: 'desc',
    usersSort: 'charged',
    usersDir: 'desc',
};

describe('reportQuery', () => {
    it('is empty for the default view, so links stay short', () => {
        expect(reportQuery(defaults)).toEqual({});
    });

    it('keeps a period that is not the default', () => {
        expect(reportQuery(defaults, { period: '7d' })).toEqual({
            period: '7d',
        });
        expect(reportQuery(defaults, { period: 'all' })).toEqual({
            period: 'all',
        });
    });

    it('takes the dates only for a custom period that has both', () => {
        expect(
            reportQuery(defaults, {
                period: 'custom',
                from: '2026-11-01',
                to: '2026-11-05',
            }),
        ).toEqual({ period: 'custom', from: '2026-11-01', to: '2026-11-05' });
        expect(
            reportQuery(defaults, { period: 'custom', from: '2026-11-01' }),
        ).toEqual({
            period: 'custom',
        });
        expect(
            reportQuery(defaults, {
                period: '7d',
                from: '2026-11-01',
                to: '2026-11-05',
            }),
        ).toEqual({ period: '7d' });
    });

    it('adds the user and the search', () => {
        expect(reportQuery(defaults, { user: 7, search: 'ann' })).toEqual({
            user: 7,
            q: 'ann',
        });
        expect(reportQuery(defaults, { search: '' })).toEqual({});
    });

    it('adds a sort that is not the default, with its direction, for each table', () => {
        expect(reportQuery(defaults, { daysDir: 'asc' })).toEqual({
            days_sort: 'day',
            days_dir: 'asc',
        });
        expect(reportQuery(defaults, { usersSort: 'profit' })).toEqual({
            users_sort: 'profit',
            users_dir: 'desc',
        });
    });

    it('adds a page only after the first', () => {
        expect(reportQuery(defaults, { daysPage: 1, usersPage: 1 })).toEqual(
            {},
        );
        expect(reportQuery(defaults, { daysPage: 3 })).toEqual({
            days_page: 3,
        });
        expect(reportQuery(defaults, { usersPage: 2 })).toEqual({
            users_page: 2,
        });
    });

    it('does not carry a page over when something else changes', () => {
        expect(reportQuery(defaults, { period: '7d' })).not.toHaveProperty(
            'days_page',
        );
    });
});

describe('nextSort', () => {
    it('flips the direction of the column that is sorted', () => {
        expect(nextSort('charged', 'desc', 'charged')).toEqual({
            sort: 'charged',
            direction: 'asc',
        });
        expect(nextSort('charged', 'asc', 'charged')).toEqual({
            sort: 'charged',
            direction: 'desc',
        });
    });

    it('starts another column with its natural direction', () => {
        expect(nextSort('charged', 'desc', 'user')).toEqual({
            sort: 'user',
            direction: 'asc',
        });
        expect(nextSort('charged', 'asc', 'profit')).toEqual({
            sort: 'profit',
            direction: 'desc',
        });
    });
});

describe('rowRange', () => {
    it('says which rows are shown', () => {
        expect(rowRange({ total: 42, page: 1, perPage: 15, lastPage: 3 })).toBe(
            '1–15 of 42',
        );
        expect(rowRange({ total: 42, page: 3, perPage: 15, lastPage: 3 })).toBe(
            '31–42 of 42',
        );
        expect(rowRange({ total: 1, page: 1, perPage: 15, lastPage: 1 })).toBe(
            '1–1 of 1',
        );
    });

    it('says when there is nothing', () => {
        expect(rowRange({ total: 0, page: 1, perPage: 15, lastPage: 1 })).toBe(
            'No rows',
        );
    });
});

describe('periodLabel', () => {
    it('writes a range of UTC dates', () => {
        expect(periodLabel('2026-10-26', '2026-11-24')).toBe(
            'Oct 26 – Nov 24, 2026',
        );
        expect(periodLabel('2025-12-20', '2026-01-10')).toBe(
            'Dec 20, 2025 – Jan 10, 2026',
        );
    });

    it('writes a single day', () => {
        expect(periodLabel('2026-11-24', '2026-11-24')).toBe('Nov 24, 2026');
    });

    it('does not move a date to another day in a time zone behind UTC', () => {
        expect(periodLabel('2026-11-01', '2026-11-01')).toBe('Nov 1, 2026');
    });

    it('says all time without dates', () => {
        expect(periodLabel(null, null)).toBe('All time');
    });
});

describe('previousPeriodLabel', () => {
    it('is the same number of days right before the period', () => {
        expect(previousPeriodLabel('2026-10-26', 30)).toBe(
            'Sep 26 – Oct 25, 2026',
        );
        expect(previousPeriodLabel('2026-11-24', 1)).toBe('Nov 23, 2026');
        expect(previousPeriodLabel('2026-01-05', 10)).toBe(
            'Dec 26, 2025 – Jan 4, 2026',
        );
    });

    it('is none for all time', () => {
        expect(previousPeriodLabel(null, null)).toBeNull();
    });
});
