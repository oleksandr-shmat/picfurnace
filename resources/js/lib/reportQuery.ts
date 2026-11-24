import type { Direction, ReportFilters, TableMeta } from '@/types/report';

export const DEFAULT_PERIOD = '30d';
export const DEFAULT_DAYS_SORT = 'day';
export const DEFAULT_USERS_SORT = 'charged';

export const PERIODS = [
    { value: 'today', label: 'Today' },
    { value: '7d', label: '7 days' },
    { value: '30d', label: '30 days' },
    { value: 'month', label: 'This month' },
    { value: 'last_month', label: 'Last month' },
    { value: 'all', label: 'All time' },
    { value: 'custom', label: 'Custom' },
] as const;

export type ReportChange = Partial<ReportFilters> & {
    daysPage?: number;
    usersPage?: number;
};

/**
 * The query string of a view of the report. A value that is what the server would use anyway is
 * left out, so links stay short, and a change of the period, the user or the search starts the
 * tables on their first page again.
 */
export function reportQuery(
    filters: ReportFilters,
    change: ReportChange = {},
): Record<string, string | number> {
    const next = { ...filters, ...change };
    const query: Record<string, string | number> = {};

    if (next.period !== DEFAULT_PERIOD) {
        query.period = next.period;
    }

    if (next.period === 'custom' && next.from && next.to) {
        query.from = next.from;
        query.to = next.to;
    }

    if (next.user !== null) {
        query.user = next.user;
    }

    if (next.search) {
        query.q = next.search;
    }

    if (next.daysSort !== DEFAULT_DAYS_SORT || next.daysDir !== 'desc') {
        query.days_sort = next.daysSort;
        query.days_dir = next.daysDir;
    }

    if (next.usersSort !== DEFAULT_USERS_SORT || next.usersDir !== 'desc') {
        query.users_sort = next.usersSort;
        query.users_dir = next.usersDir;
    }

    if (change.daysPage && change.daysPage > 1) {
        query.days_page = change.daysPage;
    }

    if (change.usersPage && change.usersPage > 1) {
        query.users_page = change.usersPage;
    }

    return query;
}

/**
 * What a click on a column heading does: the same column flips its direction, another one starts
 * with its natural direction (names from A, numbers from the biggest).
 */
export function nextSort(
    current: string,
    direction: Direction,
    clicked: string,
): { sort: string; direction: Direction } {
    if (clicked === current) {
        return {
            sort: clicked,
            direction: direction === 'asc' ? 'desc' : 'asc',
        };
    }

    return { sort: clicked, direction: clicked === 'user' ? 'asc' : 'desc' };
}

/** "1–15 of 42", or "No rows". */
export function rowRange(meta: TableMeta): string {
    if (meta.total === 0) {
        return 'No rows';
    }

    const first = (meta.page - 1) * meta.perPage + 1;
    const last = Math.min(meta.page * meta.perPage, meta.total);

    return `${first}–${last} of ${meta.total}`;
}

/**
 * The period of the same length right before one, as a label ("Sep 26 – Oct 25, 2026"). Null for
 * all time, which has nothing before it.
 */
export function previousPeriodLabel(
    from: string | null,
    days: number | null,
): string | null {
    if (from === null || days === null) {
        return null;
    }

    const dayMs = 24 * 60 * 60 * 1000;
    const start = new Date(`${from}T00:00:00Z`).getTime();
    const date = (ms: number) => new Date(ms).toISOString().slice(0, 10);

    return periodLabel(date(start - days * dayMs), date(start - dayMs));
}

/** "Oct 26 – Nov 24, 2026" (UTC dates), or "All time". */
export function periodLabel(from: string | null, to: string | null): string {
    if (from === null || to === null) {
        return 'All time';
    }

    const day = (date: string, withYear: boolean) =>
        new Intl.DateTimeFormat('en', {
            month: 'short',
            day: 'numeric',
            year: withYear ? 'numeric' : undefined,
            timeZone: 'UTC',
        }).format(new Date(`${date}T00:00:00Z`));

    if (from === to) {
        return day(from, true);
    }

    return from.slice(0, 4) === to.slice(0, 4)
        ? `${day(from, false)} – ${day(to, true)}`
        : `${day(from, true)} – ${day(to, true)}`;
}
