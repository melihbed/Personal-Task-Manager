import { describe, expect, it } from 'vitest';
import { boldPieces } from '../../resources/js/lib/assistant';

describe('boldPieces', () => {
    it('marks **bold** text and keeps the rest plain', () => {
        expect(boldPieces('Due **tomorrow** at 5')).toEqual([
            { text: 'Due ', bold: false }, { text: 'tomorrow', bold: true }, { text: ' at 5', bold: false },
        ]);
    });

    it('leaves text without emphasis alone, and unmatched stars as they are', () => {
        expect(boldPieces('Nothing special')).toEqual([{ text: 'Nothing special', bold: false }]);
        expect(boldPieces('5 * 3 = 15')).toEqual([{ text: '5 * 3 = 15', bold: false }]);
        expect(boldPieces('')).toEqual([]);
    });

    it('never produces markup: html stays text', () => {
        expect(boldPieces('<b>hi</b> **x**')).toEqual([{ text: '<b>hi</b> ', bold: false }, { text: 'x', bold: true }]);
    });
});

import { describeChange, parseStreamLines } from '../../resources/js/lib/assistant';

describe('parseStreamLines', () => {
    it('turns complete lines into events and keeps the unfinished part for later', () => {
        const first = parseStreamLines('{"type":"delta","text":"Hel"}\n{"type":"delta","text":"lo"}\n{"type":"del');

        expect(first.events).toEqual([{ type: 'delta', text: 'Hel' }, { type: 'delta', text: 'lo' }]);
        expect(first.rest).toBe('{"type":"del');

        const second = parseStreamLines(`${first.rest}ta","text":"!"}\n`);

        expect(second.events).toEqual([{ type: 'delta', text: '!' }]);
        expect(second.rest).toBe('');
    });

    it('skips blank and broken lines instead of failing the reply', () => {
        const { events } = parseStreamLines('\n{not json}\n{"type":"reset"}\n');

        expect(events).toEqual([{ type: 'reset' }]);
    });

    it('waits when there is no complete line yet', () => {
        expect(parseStreamLines('{"type":"status"')).toEqual({ events: [], rest: '{"type":"status"' });
        expect(parseStreamLines('')).toEqual({ events: [], rest: '' });
    });

    it('keeps text with accents, quotes and line breaks inside a line', () => {
        const line = JSON.stringify({ type: 'delta', text: 'Café “quoted”\nnext' });

        expect(parseStreamLines(`${line}\n`).events).toEqual([{ type: 'delta', text: 'Café “quoted”\nnext' }]);
    });
});

describe('describeChange', () => {
    it('shows a change as from and to', () => {
        expect(describeChange({ label: 'Priority', from: 'normal', to: 'high' })).toBe('Priority: normal → high');
    });

    it('shows a new value, and a removed one', () => {
        expect(describeChange({ label: 'Title', from: null, to: 'Buy a charger' })).toBe('Title: Buy a charger');
        expect(describeChange({ label: 'Notes', from: 'Chapter 3', to: null })).toBe('Notes: was Chapter 3');
    });
});
