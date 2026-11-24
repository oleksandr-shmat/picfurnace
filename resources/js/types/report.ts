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

export type TableColumn = {
    key: string;
    label: string;
    align?: 'left' | 'right';
    sortable?: boolean;
};

export type TableMeta = {
    total: number;
    page: number;
    lastPage: number;
    perPage: number;
};

/** A signed percentage against the period before ("+12.5"), or null when there is none. */
type Change = string | null;

/** A figure in money, with its value for each day for the trend line. */
type MoneyMetric = { value: string; change: Change; series: string[] };

export type ReportMetrics = {
    revenue: MoneyMetric;
    cost: MoneyMetric;
    profit: MoneyMetric & { margin: string | null };
    generations: {
        value: number;
        change: Change;
        failed: number;
        successRate: string | null;
        series: number[];
    };
    averagePrice: { value: string | null; change: Change };
    credit: { sold: string; change: Change; held: string };
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
