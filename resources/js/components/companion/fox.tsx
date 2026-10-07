export type FoxMood = 'idle' | 'work' | 'focus' | 'break' | 'celebrate' | 'sleep';

const FUR = '#ef7b35';
const FUR_SHADE = '#d9621f';
const CREAM = '#fff3e3';
const DARK = '#3b2a28';
const INK = '#252b3d';

const star = (x: number, y: number, size: number) => `M${x} ${y - size} Q${x} ${y} ${x + size} ${y} Q${x} ${y} ${x} ${y + size} Q${x} ${y} ${x - size} ${y} Q${x} ${y} ${x} ${y - size}Z`;

/**
 * A fox cub, drawn in SVG. Its mood decides which accessories show and how it moves (see the .fox rules in app.css):
 * idle breathes, blinks and swishes its tail; work writes in a notebook; focus wears headphones; break sips tea;
 * celebrate jumps; sleep curls up with a few z's.
 */
export default function Fox({ mood, className = '' }: { mood: FoxMood; className?: string }) {
    return (
        <svg viewBox="0 0 140 140" role="img" aria-label={`A fox cub, ${mood === 'idle' ? 'waiting' : mood === 'work' ? 'writing' : mood === 'focus' ? 'focusing' : mood === 'break' ? 'having a break' : mood === 'celebrate' ? 'celebrating' : 'asleep'}`} data-mood={mood} className={`fox ${className}`}>
            <ellipse cx="70" cy="130" rx="36" ry="5.5" fill={INK} opacity="0.12" />

            <g className="fox-all">
                <g className="fox-tail">
                    <path d="M88 120 C120 124 136 100 128 72 C124 62 114 60 110 68 C113 86 104 102 86 106 Z" fill={FUR} />
                    <path d="M128 72 C124 62 114 60 110 68 C115 70 122 75 128 72Z" fill={CREAM} />
                </g>

                <g className="fox-body">
                    <path d="M42 128 C38 102 48 86 70 86 C92 86 102 102 98 128 Z" fill={FUR} />
                    <path d="M57 128 C55 108 60 96 70 96 C80 96 85 108 83 128Z" fill={CREAM} />
                </g>

                <g className="fox-extras" data-show="work">
                    <g className="fox-notebook">
                        <rect x="49" y="106" width="42" height="22" rx="3" fill="#fff" stroke={INK} strokeOpacity="0.15" />
                        <path d="M55 113h30M55 118h22M55 123h26" stroke={INK} strokeOpacity="0.18" strokeWidth="1.6" strokeLinecap="round" />
                    </g>
                    <g className="fox-pencil">
                        <path d="M82 100 L93 83 L98 86 L87 103Z" fill="#f2c14e" />
                        <path d="M82 100 L87 103 L81 106Z" fill={CREAM} />
                        <path d="M81 106 L82.6 103.2 L84.4 104.4Z" fill={DARK} />
                    </g>
                </g>

                <g className="fox-extras" data-show="break">
                    <g className="fox-cup">
                        <path d="M54 108 h30 v8 a15 11 0 0 1 -30 0z" fill="#fff" stroke={INK} strokeOpacity="0.15" />
                        <path d="M84 111 a6.5 6.5 0 0 1 0 10" stroke="#fff" strokeWidth="3.2" fill="none" strokeLinecap="round" />
                        <path d="M54 112 h30" stroke={FUR} strokeWidth="2.4" />
                    </g>
                    <g className="fox-steam" fill="none" stroke="#fff" strokeWidth="2.4" strokeLinecap="round">
                        <path d="M62 102 q-4 -5 0 -10 q4 -5 0 -10" />
                        <path d="M72 102 q-4 -5 0 -10 q4 -5 0 -10" style={{ animationDelay: '0.5s' }} />
                        <path d="M82 102 q-4 -5 0 -10 q4 -5 0 -10" style={{ animationDelay: '1s' }} />
                    </g>
                </g>

                <ellipse cx="59" cy="125" rx="7" ry="5" fill={DARK} />
                <ellipse cx="81" cy="125" rx="7" ry="5" fill={DARK} />

                <g className="fox-head">
                    <g className="fox-ear fox-ear--l">
                        <path d="M33 54 C28 36 30 22 35 14 C46 18 57 28 61 41 Z" fill={FUR} />
                        <path d="M38 45 C36 35 37 27 40 22 C46 25 51 31 54 39Z" fill={DARK} opacity="0.85" />
                    </g>
                    <g className="fox-ear fox-ear--r">
                        <path d="M107 54 C112 36 110 22 105 14 C94 18 83 28 79 41 Z" fill={FUR} />
                        <path d="M102 45 C104 35 103 27 100 22 C94 25 89 31 86 39Z" fill={DARK} opacity="0.85" />
                    </g>

                    <path d="M29 63 C29 42 48 34 70 34 C92 34 111 42 111 63 C111 83 91 97 70 99 C49 97 29 83 29 63Z" fill={FUR} />
                    <path d="M31 67 C44 62 58 68 70 81 C82 68 96 62 109 67 C105 85 89 97 70 99 C51 97 35 85 31 67Z" fill={CREAM} />
                    <path d="M62 40 Q70 46 78 40 Q70 52 62 40Z" fill={FUR_SHADE} opacity="0.55" />

                    <g className="fox-eyes fox-eyes--open">
                        <g className="fox-eye"><ellipse cx="54" cy="65" rx="4.2" ry="5.2" fill={DARK} /><circle cx="55.6" cy="62.8" r="1.5" fill="#fff" /></g>
                        <g className="fox-eye"><ellipse cx="86" cy="65" rx="4.2" ry="5.2" fill={DARK} /><circle cx="87.6" cy="62.8" r="1.5" fill="#fff" /></g>
                    </g>
                    <g className="fox-eyes fox-eyes--happy" fill="none" stroke={DARK} strokeWidth="3" strokeLinecap="round">
                        <path d="M48.5 68 Q54 60 59.5 68" />
                        <path d="M80.5 68 Q86 60 91.5 68" />
                    </g>
                    <g className="fox-eyes fox-eyes--closed" fill="none" stroke={DARK} strokeWidth="3" strokeLinecap="round">
                        <path d="M48.5 65 Q54 70.5 59.5 65" />
                        <path d="M80.5 65 Q86 70.5 91.5 65" />
                    </g>

                    <ellipse cx="45" cy="77" rx="5.5" ry="3.2" fill="#ff8f8f" opacity="0.5" />
                    <ellipse cx="95" cy="77" rx="5.5" ry="3.2" fill="#ff8f8f" opacity="0.5" />
                    <ellipse cx="70" cy="82" rx="4.6" ry="3.5" fill={DARK} />
                    <path className="fox-mouth" d="M70 85.5 V88.5 M70 88.5 Q65 92.5 60.5 88.5 M70 88.5 Q75 92.5 79.5 88.5" fill="none" stroke={DARK} strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
                    <path className="fox-grin" d="M61 88 Q70 99 79 88 Q70 91.5 61 88Z" fill="#8a2b2b" stroke={DARK} strokeWidth="1.6" strokeLinejoin="round" />

                    <g className="fox-extras" data-show="focus">
                        <path d="M31 62 C31 26 109 26 109 62" fill="none" stroke={INK} strokeWidth="5" strokeLinecap="round" />
                        <rect x="24.5" y="56" width="12" height="22" rx="6" fill={INK} />
                        <rect x="103.5" y="56" width="12" height="22" rx="6" fill={INK} />
                        <circle cx="30.5" cy="67" r="2.6" fill="var(--pm-accent)" />
                        <circle cx="109.5" cy="67" r="2.6" fill="var(--pm-accent)" />
                    </g>
                </g>

                <g className="fox-extras" data-show="work" fill={INK} fillOpacity="0.55">
                    <circle className="fox-dot" cx="101" cy="26" r="3" />
                    <circle className="fox-dot" cx="110" cy="17" r="3.8" style={{ animationDelay: '0.25s' }} />
                    <circle className="fox-dot" cx="121" cy="9" r="4.6" style={{ animationDelay: '0.5s' }} />
                </g>

                <g className="fox-extras" data-show="sleep" fill={INK} fillOpacity="0.5" fontWeight="700" fontFamily="inherit">
                    <text className="fox-z" x="100" y="38" fontSize="13">z</text>
                    <text className="fox-z" x="110" y="26" fontSize="17" style={{ animationDelay: '1s' }}>z</text>
                    <text className="fox-z" x="122" y="12" fontSize="21" style={{ animationDelay: '2s' }}>z</text>
                </g>

                <g className="fox-extras" data-show="celebrate">
                    <path className="fox-spark" d={star(24, 30, 7)} fill="#f2c14e" />
                    <path className="fox-spark" d={star(118, 40, 8)} fill="#79a7cf" style={{ animationDelay: '0.2s' }} />
                    <path className="fox-spark" d={star(112, 12, 5)} fill="var(--pm-accent)" style={{ animationDelay: '0.4s' }} />
                    <path className="fox-spark" d={star(14, 66, 5)} fill="var(--pm-accent)" style={{ animationDelay: '0.1s' }} />
                    <path className="fox-spark" d={star(36, 8, 4)} fill="#79a7cf" style={{ animationDelay: '0.3s' }} />
                </g>
            </g>
        </svg>
    );
}
