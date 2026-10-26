// Gate 05 Phase B: web-specific components sharing the generated semantic tokens.
import { useEffect, useRef, type ReactNode } from 'react';

function useDialogKeyboard(open: boolean, onClose: () => void) {
    const ref = useRef<HTMLElement>(null);
    useEffect(() => {
        if (!open) return;
        const before = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        const panel = ref.current;
        panel?.querySelector<HTMLButtonElement>('button')?.focus();
        const onKeyDown = (e: KeyboardEvent) => {
            if (e.key === 'Escape') { e.preventDefault(); onClose(); return; }
            if (e.key !== 'Tab') return;
            const buttons = Array.from(panel?.querySelectorAll<HTMLButtonElement>('button:not(:disabled)') ?? []);
            if (!buttons.length) return;
            if (e.shiftKey && document.activeElement === buttons[0]) {
                e.preventDefault(); buttons[buttons.length - 1]?.focus();
            } else if (!e.shiftKey && document.activeElement === buttons[buttons.length - 1]) {
                e.preventDefault(); buttons[0]?.focus();
            }
        };
        document.addEventListener('keydown', onKeyDown);
        return () => { document.removeEventListener('keydown', onKeyDown); before?.focus(); };
    }, [open, onClose]);
    return ref;
}

type Action = { label: string; onClick: () => void };

export function MangaHeading({ children }: { children: string }) {
    return <h2 className="mb-heading">{children}</h2>;
}

export function MangaButton({ children, onClick, secondary = false, disabled = false }: {
    children: string; onClick?: () => void; secondary?: boolean; disabled?: boolean;
}) {
    return <button type="button" className={`mb-button${secondary ? ' mb-button-secondary' : ''}`}
        disabled={disabled} onClick={onClick}>{children}</button>;
}

export function MangaField({ label, value, placeholder, onChange }: {
    label: string; value: string; placeholder?: string; onChange: (value: string) => void;
}) {
    return <label className="mb-field"><span>{label}</span>
        <input aria-label={label} type="search" value={value} placeholder={placeholder}
            onChange={e => onChange(e.currentTarget.value)} />
    </label>;
}

export function MangaCover({ title }: { title: string }) {
    return <div className="mb-cover" role="img" aria-label={`غلاف تجريبي: ${title}`}>{title}</div>;
}

export function MangaRating({ value }: { value: number }) {
    const number = Number.isFinite(value) ? Math.max(0, Math.min(5, value)) : 0;
    return <span className="mb-rating">{`تقييم ${number.toFixed(1)} من 5`}</span>;
}

export function MangaProgress({ value, label }: { value: number; label: string }) {
    const n = Number.isFinite(value) ? Math.max(0, Math.min(100, value)) : 0;
    return <div className="mb-progress-wrap">
        <span>{`${label}: ${Math.round(n)}%`}</span>
        <div role="progressbar" aria-label={label} aria-valuenow={Math.round(n)} aria-valuemin={0} aria-valuemax={100}
            className="mb-progress-track"><div style={{ width: `${n}%` }} /></div>
    </div>;
}

export function MangaSkeleton({ lines = 3 }: { lines?: number }) {
    const count = Math.max(1, Math.min(6, Math.floor(lines)));
    return <div className="mb-skeleton" role="status" aria-label="جار تحميل المحتوى">
        {Array.from({ length: count }, (_, i) => <div key={i} style={{ width: i === count - 1 ? '62%' : '100%' }} />)}
    </div>;
}

export function MangaNotice({ type, message, retry }: {
    type: 'empty' | 'offline' | 'error' | 'success'; message: string; retry?: Action;
}) {
    return <div role={type === 'error' ? 'alert' : 'status'} className={`mb-panel mb-notice mb-${type}`}>
        <span>{message}</span>
        {retry && <MangaButton secondary onClick={retry.onClick}>{retry.label}</MangaButton>}
    </div>;
}

export function MangaTabs({ value, onChange, tabs }: {
    value: string; onChange: (id: string) => void; tabs: ReadonlyArray<{ id: string; title: string }>;
}) {
    return <div role="tablist" className="mb-tabs">{tabs.map(tab =>
        <button key={tab.id} role="tab" aria-selected={value === tab.id} type="button"
            onClick={() => onChange(tab.id)}>{tab.title}</button>)}</div>;
}

export function MangaConfirmDialog({ open, title, message, onClose, onConfirm }: {
    open: boolean; title: string; message: string; onClose: () => void; onConfirm: () => void;
}) {
    const ref = useDialogKeyboard(open, onClose);
    if (!open) return null;
    return <div className="mb-backdrop"><section ref={ref} role="dialog" aria-modal="true" aria-label={title} className="mb-dialog mb-panel">
        <MangaHeading>{title}</MangaHeading><p>{message}</p>
        <MangaButton onClick={onConfirm}>تأكيد</MangaButton>
        <MangaButton secondary onClick={onClose}>إلغاء</MangaButton>
    </section></div>;
}

export function MangaSheet({ open, title, onClose, children }: {
    open: boolean; title: string; onClose: () => void; children: ReactNode;
}) {
    const ref = useDialogKeyboard(open, onClose);
    if (!open) return null;
    return <div className="mb-backdrop mb-backdrop-bottom"><section ref={ref} role="dialog" aria-modal="true" aria-label={title}
        className="mb-sheet mb-panel"><MangaHeading>{title}</MangaHeading>{children}
        <MangaButton secondary onClick={onClose}>إغلاق</MangaButton></section></div>;
}

export function MangaToast({ message, onClose }: { message: string; onClose: () => void }) {
    return <div className="mb-toast" role="status"><span>{message}</span>
        <MangaButton secondary onClick={onClose}>إخفاء الرسالة</MangaButton></div>;
}
