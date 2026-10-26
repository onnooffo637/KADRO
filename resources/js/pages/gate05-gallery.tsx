// Gate 05 local-only browser gallery. Laravel route is registered for APP_ENV=local.
import { useState } from 'react';
import '../../css/generated/manga-tokens.css';
import '../../css/gate05-gallery.css';

type Theme = 'dark' | 'light';

export default function Gate05Gallery() {
    const [theme, setTheme] = useState<Theme>('dark');
    const [direction, setDirection] = useState<'rtl' | 'ltr'>('rtl');
    const [category, setCategory] = useState('مانهوا');
    return (
        <main className="manga-gallery" data-manga-theme={theme} dir={direction}>
            <div className="manga-gallery-inner">
                <p className="manga-kicker">GATE 05 / COMPONENT GALLERY</p>
                <h1>عالم المانجا والمانهوا</h1>
                <p className="manga-muted">Zain + Roboto Slab • مدينة الغيم • Chapter 01</p>
                <div className="manga-actions">
                    <button onClick={() => setTheme(t => t === 'dark' ? 'light' : 'dark')}>
                        {theme === 'dark' ? 'الوضع الفاتح' : 'الوضع الداكن'}
                    </button>
                    <button className="manga-secondary" onClick={() => setDirection(d => d === 'rtl' ? 'ltr' : 'rtl')}>
                        {direction === 'rtl' ? 'LTR اختبار' : 'RTL اختبار'}
                    </button>
                </div>
                <section className="manga-panel manga-feature" aria-label="Sample manga card">
                    <div className="manga-placeholder" aria-label="Original placeholder artwork, not copyrighted manga">MANGA</div>
                    <div className="manga-feature-info">
                        <h2>مدينة الغيم</h2>
                        <p className="manga-muted">Fantasy / مانهوَا تجريبية</p>
                        <p className="manga-muted">التقدم: 65%</p>
                        <div className="manga-progress"><span /></div>
                    </div>
                </section>
                <div className="manga-chips" role="group" aria-label="Type selection">
                    {['مانجا', 'مانهوا', 'ويب تون'].map(x => (
                        <button key={x} aria-pressed={category === x} className={`manga-chip ${category === x ? 'is-selected' : ''}`}
                            onClick={() => setCategory(x)}>{x}</button>
                    ))}
                </div>
                <button disabled>ابدأ القراءة</button>
                <div className="manga-states">
                    <div className="manga-panel">لا توجد أعمال بعد</div>
                    <div className="manga-panel">لا يوجد اتصال بالخادم المحلي</div>
                    <div className="manga-panel manga-danger">تعذر تحميل البيانات</div>
                    <div className="manga-panel manga-success">تمت العملية بنجاح</div>
                </div>
                <p className="manga-muted manga-note">هذه معاينة مكوّنات محلية وليست شاشة إنتاج. لا تُعكس أغلفة وصفحات المانجا في RTL.</p>
            </div>
        </main>
    );
}
