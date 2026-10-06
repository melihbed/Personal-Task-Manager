export type PlannerTask = {
    id: number; responsibility_id: number | null; title: string; priority: string;
    due_at: string | null; completed_at: string | null; calendar_sessions_count: number;
};
export type PlannerSession = {
    id: number; task_id: number; title: string; responsibility_name: string;
    color: string | null; completed: boolean; starts_at: string; ends_at: string;
};

export function addDays(date: string, count: number): string {
    const value = new Date(`${date}T12:00:00Z`);
    value.setUTCDate(value.getUTCDate() + count);
    return value.toISOString().slice(0, 10);
}

export function dateLabel(date: string, options: Intl.DateTimeFormatOptions): string {
    return new Intl.DateTimeFormat(undefined, { ...options, timeZone: 'UTC' }).format(new Date(`${date}T12:00:00Z`));
}

export function zonedParts(value: Date, timezone: string) {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit',
        hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23',
    }).formatToParts(value);
    const part = (name: string) => parts.find(item => item.type === name)?.value ?? '00';
    return { date: `${part('year')}-${part('month')}-${part('day')}`, time: `${part('hour')}:${part('minute')}`, second: part('second') };
}

// Convert a wall-clock date/time in an IANA timezone to an explicit UTC instant.
// Verify round-trip to reject nonexistent daylight-saving times.
export function localToISO(date: string, time: string, timezone: string): string {
    const wanted = `${date}T${time}:00`;
    const wall = Date.parse(`${wanted}Z`);
    if (!Number.isFinite(wall)) throw new Error('Choose a valid date and time.');
    let instant = wall;
    for (let i = 0; i < 5; i++) {
        const parts = zonedParts(new Date(instant), timezone);
        const shown = Date.parse(`${parts.date}T${parts.time}:${parts.second}Z`);
        const adjustment = wall - shown;
        if (!adjustment) break;
        instant += adjustment;
    }
    const check = zonedParts(new Date(instant), timezone);
    if (`${check.date}T${check.time}:${check.second}` !== wanted) {
        throw new Error('That time does not exist because of daylight saving. Choose another time.');
    }
    // During the repeated fall-back hour, choose the earlier matching occurrence.
    const earlier = zonedParts(new Date(instant - 3600000), timezone);
    if (`${earlier.date}T${earlier.time}:${earlier.second}` === wanted) instant -= 3600000;
    return new Date(instant).toISOString();
}

export function overlaps(aStart: string, aEnd: string, bStart: string, bEnd: string): boolean {
    return Date.parse(aStart) < Date.parse(bEnd) && Date.parse(aEnd) > Date.parse(bStart);
}

export function timeLabel(iso: string, timezone: string): string {
    return new Intl.DateTimeFormat(undefined, { timeZone: timezone, hour: 'numeric', minute: '2-digit' }).format(new Date(iso));
}

export function dayLayout(sessions: PlannerSession[], date: string, timezone: string) {
    const dayStart = localToISO(date, '00:00', timezone);
    const dayEnd = localToISO(addDays(date, 1), '00:00', timezone);
    const minute = (iso: string) => {
        const p = zonedParts(new Date(iso), timezone);
        const [h, m] = p.time.split(':').map(Number);
        return h * 60 + m;
    };
    const items = sessions.filter(session => overlaps(session.starts_at, session.ends_at, dayStart, dayEnd)).map(session => {
        const start = Date.parse(session.starts_at) <= Date.parse(dayStart) ? 0 : minute(session.starts_at);
        const end = Date.parse(session.ends_at) >= Date.parse(dayEnd) ? 1440 : minute(session.ends_at);
        return { session, start, end: Math.max(end, start + 1), lane: 0, laneCount: 1 };
    }).sort((a, b) => a.start - b.start || b.end - a.end);
    let group: typeof items = [];
    let groupEnd = -1;
    const finish = () => {
        const lanes: number[] = [];
        for (const item of group) {
            let lane = lanes.findIndex(end => end <= item.start);
            if (lane < 0) lane = lanes.length;
            lanes[lane] = item.end;
            item.lane = lane;
        }
        for (const item of group) item.laneCount = lanes.length;
    };
    for (const item of items) {
        if (item.start >= groupEnd) { finish(); group = []; groupEnd = -1; }
        group.push(item); groupEnd = Math.max(groupEnd, item.end);
    }
    finish();
    return items;
}
