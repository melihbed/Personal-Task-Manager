import test from 'node:test';
import assert from 'node:assert/strict';
import { addDays, localToISO, overlaps, dayLayout } from '../../resources/js/lib/planner.ts';

test('date arithmetic crosses month and year boundaries', () => {
    assert.equal(addDays('2026-12-28', 6), '2027-01-03');
    assert.equal(addDays('2026-10-05', -7), '2026-09-28');
});
test('wall-clock times resolve in New York and Istanbul', () => {
    assert.equal(localToISO('2026-10-05', '09:00', 'America/New_York'), '2026-10-05T13:00:00.000Z');
    assert.equal(localToISO('2026-10-05', '09:00', 'Europe/Istanbul'), '2026-10-05T06:00:00.000Z');
});
test('reject nonexistent DST time and choose earlier repeated occurrence', () => {
    assert.throws(() => localToISO('2026-03-08', '02:30', 'America/New_York'), /does not exist/);
    assert.equal(localToISO('2026-11-01', '01:30', 'America/New_York'), '2026-11-01T05:30:00.000Z');
});
test('touching sessions are allowed but actual intersections conflict', () => {
    assert.equal(overlaps('2026-10-05T09:00Z','2026-10-05T10:00Z','2026-10-05T10:00Z','2026-10-05T11:00Z'), false);
    assert.equal(overlaps('2026-10-05T09:00Z','2026-10-05T10:00Z','2026-10-05T09:30Z','2026-10-05T11:00Z'), true);
});
const session = (id, start, end) => ({ id, task_id: id, title: 'Task', responsibility_name: 'Inbox', color: null, completed: false, starts_at: start, ends_at: end });
test('overlaps receive separate lanes, adjacent session uses full width', () => {
    const result = dayLayout([session(1,'2026-10-05T09:00Z','2026-10-05T10:00Z'),session(2,'2026-10-05T09:30Z','2026-10-05T10:30Z'),session(3,'2026-10-05T10:30Z','2026-10-05T11:00Z')], '2026-10-05','UTC');
    assert.deepEqual(result.map(i => [i.lane, i.laneCount]), [[0,2],[1,2],[0,1]]);
});
test('overnight session is clipped at midnight in each day', () => {
    const data = [session(1,'2026-10-05T23:00Z','2026-10-06T01:00Z')];
    assert.deepEqual(dayLayout(data,'2026-10-05','UTC').map(i => [i.start,i.end]), [[1380,1440]]);
    assert.deepEqual(dayLayout(data,'2026-10-06','UTC').map(i => [i.start,i.end]), [[0,60]]);
});
