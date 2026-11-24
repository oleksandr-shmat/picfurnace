export type Direction = 'asc' | 'desc';

export type ReportFilters = {
    period: string;
    /** Only for a custom period. */
    from: string | null;
    to: string | null;
    user: number | null;
    search: string | null;
    daysSort: string;
    daysDir: Direction;
    usersSort: string;
    usersDir: Direction;
};

export type ReportRange = {
    /** The first and the last day, as dates; null for all time. */
    from: string | null;
    to: string | null;
    days: number | null;
};

/** Money is an exact decimal string from the server, rounded only where it is shown. */
export type ReportLine = {
    key: string;
    label: string | null;
    generations: number;
    charged: string;
    providerCost: string;
    profit: string;
    lastActivity: string | null;
    balance: string | null;
};

export type TableMeta = {
    total: number;
    page: number;
    lastPage: number;
    perPage: number;
};

export type Metric = {
    value: string | number | null;
    /** A signed percentage against the period before, or null. */
    change: string | null;
};

export type ReportMetrics = {
    revenue: Metric & { series: string[] };
    cost: Metric & { series: string[] };
    profit: Metric & { margin: string | null; series: string[] };
    generations: Metric & {
        failed: number;
        successRate: string | null;
        series: number[];
    };
    averagePrice: Metric;
    credit: { sold: string; change: string | null; held: string };
};

export type ReportTotals = {
    generations: number;
    charged: string;
    providerCost: string;
    profit: string;
};

export type LedgerStatus = {
    agrees: boolean;
    onGenerations: string;
    inLedger: string;
};

export type UserOption = { id: number; label: string };
