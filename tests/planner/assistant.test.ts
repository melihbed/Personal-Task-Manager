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
