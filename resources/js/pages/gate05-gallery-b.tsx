// Gate 05 Phase B local-only browser gallery; sample data is fictitious.
import { useState } from 'react';
import '../../css/generated/manga-tokens.css';
import '../../css/gate05-gallery.css';
import '../../css/gate05-gallery-b.css';
import '../../css/gate05-gallery-c.css';
import { MangaAccessibilityPreview } from '../components/manga/MangaAccessibility';
import {
    MangaButton, MangaConfirmDialog, MangaCover, MangaField, MangaHeading, MangaNotice,
    MangaProgress, MangaRating, MangaSheet, MangaSkeleton, MangaTabs, MangaToast,
} from '../components/manga/MangaPhaseB';

type Theme = 'dark' | 'light';
type Direction = 'rtl' | 'ltr';

export default function Gate05GalleryB() {
    const [theme, setTheme] = useState<Theme>('dark');
    const [direction, setDirection] = useState<Direction>('rtl');
    const [query, setQuery] = useState('');
    const [category, setCategory] = useState('مانهوا');
    const [tab, setTab] = useState('home');
    const [dialog, setDialog] = useState(false);
    const [sheet, setSheet] = useState(false);
    const [toast, setToast] = useState(false);
    return <main className="manga-gallery mb-gallery" data-manga-theme={theme} dir={direction}>
        <div className="manga-gallery-inner mb-inner">
            <p className="manga-kicker">GATE 05 / PHASE B / UI LIBRARY</p>
            <h1>عالم المانجا والمانهوا</h1>
            <p className="manga-muted">Component Gallery • Zain + Roboto Slab</p>
            <div className="manga-actions">
                <MangaButton onClick={() => setTheme(v => v === 'dark' ? 'light' : 'dark')}>
                    {theme === 'dark' ? 'الوضع الفاتح' : 'الوضع الداكن'}
                </MangaButton>
                <MangaButton secondary onClick={() => setDirection(v => v === 'rtl' ? 'ltr' : 'rtl')}>
                    {direction === 'rtl' ? 'LTR اختبار' : 'RTL اختبار'}
                </MangaButton>
            </div>
            <MangaField label="البحث عن عمل" value={query} placeholder="ابحث عن مانجا أو مانهوا" onChange={setQuery} />
            <div className="mb-panel mb-feature">
                <MangaCover title="مدينة الغيم" />
                <div className="mb-feature-copy">
                    <MangaHeading>مدينة الغيم</MangaHeading>
                    <p className="manga-muted">Fantasy / Chapter 01</p>
                    <MangaRating value={4.6} />
                    <MangaProgress value={65} label="التقدم" />
                </div>
            </div>
            <div className="manga-chips" role="group" aria-label="نوع العمل">
                {['مانجا', 'مانهوا', 'ويب تون'].map(name => <button type="button" key={name}
                    className={`manga-chip${category === name ? ' is-selected' : ''}`}
                    aria-pressed={category === name} onClick={() => setCategory(name)}>{name}</button>)}
            </div>
            <MangaTabs value={tab} onChange={setTab} tabs={[
                { id: 'home', title: 'الرئيسية' }, { id: 'explore', title: 'استكشف' }, { id: 'library', title: 'مكتبتي' },
            ]} />
            <MangaAccessibilityPreview direction={direction} />
            <MangaHeading>حالة التحميل</MangaHeading>
            <div className="mb-panel"><MangaSkeleton lines={3} /></div>
            <MangaHeading>حالات التطبيق</MangaHeading>
            <div className="mb-states">
                <MangaNotice type="empty" message="لا توجد أعمال بعد" />
                <MangaNotice type="offline" message="الخادم المحلي غير متصل" />
                <MangaNotice type="error" message="تعذر تحميل الأعمال"
                    retry={{ label: 'إعادة المحاولة (تجريبي)', onClick: () => setToast(true) }} />
                <MangaNotice type="success" message="تم الحفظ بنجاح" />
            </div>
            <div className="manga-actions">
                <MangaButton onClick={() => setDialog(true)}>افتح نافذة التأكيد</MangaButton>
                <MangaButton secondary onClick={() => setSheet(true)}>افتح اللوحة السفلية</MangaButton>
            </div>
            {toast && <MangaToast message="هذه رسالة تجريبية وليست عملية حقيقية" onClose={() => setToast(false)} />}
            <p className="manga-muted manga-note">هذه بيانات خيالية للمعاينة فقط؛ RTL لا يعكس صور صفحات المانجا.</p>
        </div>
        <MangaConfirmDialog open={dialog} title="تأكيد تجريبي" message="لا توجد بيانات حقيقية سيتم تغييرها"
            onClose={() => setDialog(false)} onConfirm={() => { setDialog(false); setToast(true); }} />
        <MangaSheet open={sheet} title="اختيارات القراءة" onClose={() => setSheet(false)}>
            <p>التمرير العمودي — مثال مرئي فقط</p>
            <p>تقليب الصفحات — مثال مرئي فقط</p>
        </MangaSheet>
    </main>;
}
