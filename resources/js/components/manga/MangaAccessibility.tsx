// Gate 05 Phase C: browser accessibility and Lucide icon samples.
import { useState, type CSSProperties } from 'react';
import {
    ArrowRight, BookOpen, Languages, Moon, Sun,
    type LucideIcon,
} from 'lucide-react';

type IconName = 'next' | 'book' | 'language' | 'moon' | 'sun';
const icons: Record<IconName, LucideIcon> = {
    next: ArrowRight, book: BookOpen, language: Languages,
    moon: Moon, sun: Sun,
};

export function MangaWebIcon({ name, mirrored = false }: {
    name: IconName; mirrored?: boolean;
}) {
    const Icon = icons[name];
    return <Icon aria-hidden="true" focusable="false"
        className={mirrored && name === 'next' ? 'mc-icon-directional' : undefined}
        size={20} strokeWidth={1.8} />;
}

export function MangaAccessibilityPreview({ direction }: { direction: 'rtl' | 'ltr' }) {
    const [scale, setScale] = useState(1);
    const [reduceMotion, setReduceMotion] = useState(false);
    const sizeStyle = { '--manga-preview-scale': scale } as CSSProperties;
    return <section className="mb-panel mc-access" aria-label="معاينة الوصولية" dir={direction}>
        <h2 className="mb-heading">الوصولية وتكبير النص</h2>
        <p className="manga-muted">تحقق من التفاف النصوص، ودعم لوحة المفاتيح وتقليل الحركة.</p>
        <div className="mc-options" role="group" aria-label="حجم النص">
            {[1, 1.25, 1.5].map(value => <button key={value} type="button"
                aria-pressed={scale === value} className="manga-secondary"
                onClick={() => setScale(value)}>{`${Math.round(value * 100)}%`}</button>)}
        </div>
        <div className="mc-text-preview" style={sizeStyle}>
            <p><strong>هذه مانهوَا تجريبية — رحلة مدينة الغيم</strong></p>
            <p>Chapter 01 / الجزء الأول: Mixed Arabic and English typography for larger screens and zoom.</p>
        </div>
        <button type="button" className="manga-secondary"
            role="switch" aria-checked={reduceMotion}
            onClick={() => setReduceMotion(v => !v)}>
            {reduceMotion ? 'تقليل الحركة: مفعّل' : 'تقليل الحركة: غير مفعّل'}
        </button>
        <p className="manga-muted manga-note">هذه محاكاة، ولا تغير تفضيلات النظام. استخدم Tab وShift+Tab لفحص التركيز.</p>
        <div className="mc-icon-row" aria-label="أيقونات Lucide على الويب">
            <span><MangaWebIcon name="book" />المكتبة</span>
            <span><MangaWebIcon name="language" />اللغات</span>
            <span><MangaWebIcon name="moon" />داكن</span>
            <span><MangaWebIcon name="sun" />فاتح</span>
            <span><MangaWebIcon name="next" mirrored={direction === 'rtl'} />التالي</span>
        </div>
    </section>;
}
