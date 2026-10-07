import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';

type Companion = {
    /** The fox is jumping for joy: something good just happened. */
    celebrating: boolean;
    /** The assistant is working on an answer. */
    thinking: boolean;
    celebrate: () => void;
    setThinking: (thinking: boolean) => void;
};

const CELEBRATION_MS = 2600;

const CompanionContext = createContext<Companion>({ celebrating: false, thinking: false, celebrate: () => {}, setThinking: () => {} });

export const useCompanion = () => useContext(CompanionContext);

/** What the fox is reacting to. The pages and panels that cause a reaction tell it here. */
export function CompanionProvider({ children }: { children: ReactNode }) {
    const [celebrating, setCelebrating] = useState(false);
    const [thinking, setThinking] = useState(false);
    const timer = useRef<number | undefined>(undefined);

    const celebrate = useCallback(() => {
        window.clearTimeout(timer.current);
        setCelebrating(true);
        timer.current = window.setTimeout(() => setCelebrating(false), CELEBRATION_MS);
    }, []);

    useEffect(() => () => window.clearTimeout(timer.current), []);

    const value = useMemo(() => ({ celebrating, thinking, celebrate, setThinking }), [celebrating, thinking, celebrate]);

    return <CompanionContext.Provider value={value}>{children}</CompanionContext.Provider>;
}
