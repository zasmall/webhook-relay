const relative = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

const units: [Intl.RelativeTimeFormatUnit, number][] = [
    ['year', 31_536_000],
    ['month', 2_592_000],
    ['day', 86_400],
    ['hour', 3_600],
    ['minute', 60],
    ['second', 1],
];

/** "3 minutes ago" / "in 2 hours". */
export function relativeTime(iso: string, now: number = Date.now()): string {
    const seconds = Math.round((new Date(iso).getTime() - now) / 1000);

    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size || unit === 'second') {
            return relative.format(Math.round(seconds / size), unit);
        }
    }

    return relative.format(0, 'second');
}

export function absoluteTime(iso: string): string {
    return new Date(iso).toLocaleString();
}

/** 0.987 → "98.7%"; null → "—". */
export function percent(rate: number | null): string {
    if (rate === null) {
        return '—';
    }

    return `${(Math.floor(rate * 1000) / 10).toString()}%`;
}
