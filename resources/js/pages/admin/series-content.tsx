import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEvent, useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

interface Work {
    id: string; title: string; slug: string; status: string; format: string;
    author_name: string | null; illustrator_name: string | null; age_rating: string | null;
    original_language: string; cover_url: string | null; is_original_content: boolean;
}
interface Edition { id: string; label: string; language: string; status: string; reading_direction: string }
interface Page { id: string; index: number; url: string; width: number; height: number }
interface Revision { id: string; revision_no: number; status: string; pages: Page[] }
interface Chapter { id: string; edition_id: string; display_number: string; sort_key: string;
    title: string | null; status: string; published_revision_id: string | null; issues: string[]; revisions: Revision[] }
interface License { id: string; holder_name: string; status: string; valid_from: string; valid_until: string }
interface Grant { id: string; license_id: string; edition_id: string | null; status: string; territories: string[]; languages: string[] }
interface ChapterImport { id: string; chapter_id: string; status: 'queued' | 'processing' | 'completed' | 'failed'; revision_id: string | null; error_message: string | null; created_at: string }
interface Props { series: Work; editions: Edition[]; chapters: Chapter[]; licenses: License[]; grants: Grant[]; imports: ChapterImport[]; async_imports_enabled: boolean; success?: string | null }

const labelClass = 'mb-1 block text-sm font-semibold';
const selectClass = 'h-10 w-full rounded-md border bg-background px-3 text-sm';
const cardClass = 'admin-card space-y-4 p-4 md:p-6';
const MiB = 1024 * 1024;
const MAX_SINGLE_UPLOAD = 60 * MiB;
const MAX_IMAGE = 12 * MiB;
const MAX_IMAGES = 200;
const formatMB = (bytes: number) => `${(bytes / MiB).toFixed(1)} MB`;
const formatLabels: Record<string, string> = { manga: 'مانجا', manhwa: 'مانهوا', manhua: 'مانهوا صينية', webtoon: 'ويب تون' };
const directionLabels: Record<string, string> = { auto: 'تلقائي', vertical: 'عمودي', rtl: 'يمين إلى يسار', ltr: 'يسار إلى يمين' };
const statusLabels: Record<string, string> = {
    draft: 'مسودة', reviewed: 'قيد المراجعة', published: 'منشور', archived: 'مؤرشف', revoked: 'مسحوب',
    pending: 'بانتظار المراجعة', approved: 'معتمد', active: 'نشط', failed: 'فشل', completed: 'مكتمل',
};
function newImportRequestKey(): string {
    // Only called in browser event handlers; never during Inertia SSR.
    return window.crypto.randomUUID();
}


function Errors({ errors }: { errors: Record<string, string> }) {
    if (Object.keys(errors).length === 0) return null;
    return <div role="alert" className="admin-editor-message admin-editor-message--error space-y-1">
        <p className="font-bold">راجع البيانات التالية:</p>
        {Object.entries(errors).map(([field, message]) => <p key={field}>{field}: {message}</p>)}
    </div>;
}

export default function SeriesContent({ series, editions, chapters, licenses, grants, imports = [], async_imports_enabled = false, success }: Props) {
    const page = usePage();
    const errors = (page.props.errors ?? {}) as Record<string, string>;
    const notice = success ?? (page.props.success as string | undefined);
    const prefix = `/admin/series/${series.id}`;
    const [currentEdition, setCurrentEdition] = useState(editions[0]?.id ?? '');
    const [currentChapter, setCurrentChapter] = useState('');
    const [tab, setTab] = useState<'info' | 'editions' | 'chapters' | 'rights'>('info');
    // Support direct navigation from the draft editor to a specific content tab.
    useEffect(() => {
        const requested = new URLSearchParams(window.location.search).get('tab');
        if (requested === 'info' || requested === 'editions' || requested === 'chapters' || requested === 'rights') {
            setTab(requested);
        }
    }, []);
    useEffect(() => {
        if (!editions.some(ed => ed.id === currentEdition)) setCurrentEdition(editions[0]?.id ?? '');
    }, [editions, currentEdition]);
    // Poll only while queued work exists. It keeps upload state after closing/reopening the page.
    useEffect(() => {
        if (!async_imports_enabled || !imports.some(item => item.status === 'queued' || item.status === 'processing')) return;
        const timer = window.setInterval(() => router.reload({ only: ['chapters', 'imports'] }), 3500);
        return () => window.clearInterval(timer);
    }, [async_imports_enabled, imports]);
    const chosenChapter = chapters.find(ch => ch.id === currentChapter) ?? chapters.find(ch => ch.edition_id === currentEdition);
    const chosenEdition = editions.find(ed => ed.id === currentEdition);
    const revision = chosenChapter?.revisions[0];

    const details = useForm({ author_name: series.author_name ?? '', illustrator_name: series.illustrator_name ?? '', age_rating: series.age_rating ?? '' });
    const cover = useForm<{ upload: File | null }>({ upload: null });
    const edition = useForm({ label: 'النسخة العربية', language_code: 'ar', reading_direction_hint: 'auto' });
    const chapter = useForm({ display_number: '1', sort_key: '1', title: '', kind: 'regular' });
    const pagesUpload = useForm<{ upload: File | null; images: File[]; import_request_key: string }>({ upload: null, images: [], import_request_key: '' });
    const [uploadValidation, setUploadValidation] = useState('');
    const [uploadState, setUploadState] = useState<'idle' | 'sending' | 'processing' | 'error' | 'success'>('idle');
    const [inputVersion, setInputVersion] = useState(0);

    function resetUpload(): void {
        pagesUpload.setData({ upload: null, images: [], import_request_key: '' });
        setUploadValidation('');
        setUploadState('idle');
        setInputVersion(value => value + 1);
    }
    function chooseFile(file: File | null): void {
        const problem = file && (file.size < 1 || file.size > MAX_SINGLE_UPLOAD ||
            (/\.(jpe?g|png|webp)$/i.test(file.name) && file.size > MAX_IMAGE))
            ? 'حجم الملف غير مسموح: حتى 60MB للأرشيف/PDF و12MB للصورة.' : '';
        pagesUpload.setData({ upload: file, images: [], import_request_key: file ? newImportRequestKey() : '' });
        setUploadValidation(problem);
        setUploadState('idle');
    }
    function chooseImages(files: File[]): void {
        const total = files.reduce((sum, file) => sum + file.size, 0);
        const invalid = files.some(file => !/\.(jpe?g|png|webp)$/i.test(file.name) || file.size < 1 || file.size > MAX_IMAGE);
        const problem = files.length > MAX_IMAGES || total > MAX_SINGLE_UPLOAD || invalid
            ? 'المسموح حتى 200 صورة، 12MB لكل صورة، و60MB للمجموعة؛ بصيغ JPG/PNG/WebP.' : '';
        pagesUpload.setData({ upload: null, images: files, import_request_key: files.length ? newImportRequestKey() : '' });
        setUploadValidation(problem);
        setUploadState('idle');
    }
    const selectedBytes = pagesUpload.data.upload?.size ?? pagesUpload.data.images.reduce((sum, file) => sum + file.size, 0);

    const [chapterUploadMode, setChapterUploadMode] = useState<'file' | 'images'>('file');
    const license = useForm<{ holder_name: string; valid_from: string; valid_until: string; document: File | null }>(
        { holder_name: '', valid_from: '', valid_until: '', document: null });
    const grant = useForm({ license_id: '', edition_id: currentEdition, territory_code: 'EG', can_translate: false });
    const [acceptProof, setAcceptProof] = useState(false);

    function submit(event: FormEvent<HTMLFormElement>, action: () => void) { event.preventDefault(); action(); }
    const buttonLabel = (pending: boolean, text: string) => pending ? 'جارٍ التنفيذ…' : text;

    return <>
        <Head title={`إدارة محتوى ${series.title}`} />
        <section dir="rtl" className="admin-editor mx-auto w-full max-w-6xl space-y-6 px-4 py-6 md:px-8">
            <header className="admin-editor-hero space-y-3">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <span className="admin-editor-kicker">استوديو إدارة المحتوى</span>
                    <Link className="text-sm font-semibold underline underline-offset-4" href="/admin/series">← كل الأعمال</Link>
                </div>
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div><h1>{series.title}</h1>
                        <p className="mt-2 text-sm"><bdi dir="ltr">{series.slug}</bdi> · {formatLabels[series.format] ?? series.format}</p></div>
                    {series.status === 'draft' && <Link className="rounded-xl border border-white/25 bg-white/10 px-4 py-2 text-sm font-semibold" href={`${prefix}/edit`}>تعديل بيانات المسودة</Link>}
                </div>
                <p className="text-sm">الغلاف والبيانات، ثم الإصدار والفصول، وبعدها مراجعة الحقوق والجاهزية قبل النشر.</p>
                <span className="admin-editor-state">{series.status === 'published' ? 'منشور للقراء' : series.status === 'draft' ? 'مسودة خاصة' : statusLabels[series.status] ?? series.status}</span>
            </header>
            {notice && <p role="status" className="admin-editor-message admin-editor-message--success">{notice}</p>}
            <Errors errors={errors} />

            <nav aria-label="أقسام المحتوى" className="admin-editor-tabs grid grid-cols-2 gap-2 sm:flex sm:flex-wrap">
                {([
                    ['info', 'بيانات العمل والغلاف', '٠١'], ['editions', 'الإصدارات', '٠٢'],
                    ['chapters', 'الفصول والصفحات', '٠٣'], ['rights', 'الحقوق والنشر', '٠٤'],
                ] as const).map(([key, value, number]) => <Button type="button" key={key}
                    className="h-auto min-h-11 gap-2 whitespace-normal px-2 py-2 text-xs sm:text-sm"
                    aria-current={tab === key ? 'page' : undefined}
                    variant={tab === key ? 'default' : 'outline'} onClick={() => setTab(key)}><span className="admin-editor-tab-number">{number}</span>{value}</Button>)}
            </nav>

            {tab === 'info' && <div className="grid gap-5 lg:grid-cols-2">
                <div className={cardClass}>
                    <h2 className="font-bold">الغلاف الرئيسي — تخزين خاص</h2>
                    <p className="text-sm text-muted-foreground">غلاف العمل الظاهر في المكتبة. رفع صورة جديدة لا ينشر فصلًا.</p>
                    {series.cover_url ? <img src={series.cover_url} alt={`غلاف ${series.title}`} className="admin-editor-cover-image max-h-72 max-w-full rounded object-contain" />
                        : <div className="admin-editor-cover-placeholder flex min-h-40 items-center justify-center p-5 text-sm text-muted-foreground">لم يتم رفع غلاف بعد</div>}
                    {['draft', 'published'].includes(series.status) && <form className="space-y-3" onSubmit={e => submit(e, () => cover.post(`${prefix}/cover`, { forceFormData: true, preserveScroll: true, onSuccess: () => cover.reset() }))}>
                        <label className={labelClass} htmlFor="cover">اختر صورة JPG/PNG/WebP لا تتجاوز 12 ميجابايت</label>
                        <Input id="cover" type="file" required accept="image/jpeg,image/png,image/webp"
                            onChange={e => cover.setData('upload', e.currentTarget.files?.[0] ?? null)} />
                        {cover.progress && <p>تقدم الرفع: {cover.progress.percentage}%</p>}
                        <Button disabled={cover.processing || !cover.data.upload}>{buttonLabel(cover.processing, 'رفع الغلاف')}</Button>
                    </form>}
                </div>
                <form className={cardClass} onSubmit={e => submit(e, () => details.put(`${prefix}/content/details`, { preserveScroll: true }))}>
                    <h2 className="font-bold">بيانات المؤلف والرسام والفئة العمرية</h2>
                    <p className="text-sm text-muted-foreground">حدث بيانات العرض لهذا العمل مع بقاء حالة النشر كما هي.</p>
                    <label htmlFor="author" className={labelClass}>المؤلف</label>
                    <Input id="author" maxLength={200} value={details.data.author_name} onChange={e => details.setData('author_name', e.target.value)} />
                    <label htmlFor="illustrator" className={labelClass}>الرسام</label>
                    <Input id="illustrator" maxLength={200} value={details.data.illustrator_name} onChange={e => details.setData('illustrator_name', e.target.value)} />
                    <label htmlFor="age" className={labelClass}>الفئة العمرية</label>
                    <select id="age" className={selectClass} value={details.data.age_rating} onChange={e => details.setData('age_rating', e.target.value)}>
                        <option value="">غير محددة</option><option value="all">الكل</option><option value="12+">12+</option>
                        <option value="16+">16+</option><option value="18+">18+</option>
                    </select>
                    <Button disabled={details.processing || !['draft', 'published'].includes(series.status)}>حفظ البيانات</Button>
                </form>
            </div>}

            {tab === 'editions' && <div className="grid gap-5 lg:grid-cols-2">
                <div className={cardClass}><h2 className="font-bold">الإصدارات الحالية</h2>
                    <p className="text-sm text-muted-foreground">كل لغة وإعداد قراءة لها إصدار مستقل.</p>
                    {editions.length === 0 && <p className="admin-editor-note p-4 text-sm">لا توجد إصدارات بعد. أنشئ أول إصدار من البطاقة التالية.</p>}
                    {editions.map(ed => <div className="admin-editor-choice" key={ed.id}>
                        <span className="min-w-0"><strong className="block">{ed.label}</strong><span className="text-xs text-muted-foreground">{ed.language} · {directionLabels[ed.reading_direction] ?? ed.reading_direction}</span></span>
                        <span className="rounded-full bg-[#efedff] px-2 py-1 text-xs font-semibold text-[#554dd5]">{statusLabels[ed.status] ?? ed.status}</span>
                    </div>)}
                </div>
                <form className={cardClass} onSubmit={e => submit(e, () => edition.post(`${prefix}/editions`, { preserveScroll: true }))}>
                    <h2 className="font-bold">إنشاء إصدار جديد كمسودة</h2>
                    <p className="text-sm text-muted-foreground">اختَر اللغة واتجاه القراءة. إنشاء الإصدار لا يجعله منشورًا.</p>
                    <label className={labelClass} htmlFor="edition_name">اسم الإصدار</label>
                    <Input id="edition_name" value={edition.data.label} onChange={e => edition.setData('label', e.target.value)} required />
                    <label className={labelClass} htmlFor="edition_lang">لغة الإصدار</label>
                    <Input id="edition_lang" dir="ltr" value={edition.data.language_code} onChange={e => edition.setData('language_code', e.target.value)} required />
                    <label className={labelClass} htmlFor="direction">اتجاه القراءة</label>
                    <select id="direction" className={selectClass} value={edition.data.reading_direction_hint} onChange={e => edition.setData('reading_direction_hint', e.target.value)}>
                        <option value="auto">تلقائي</option><option value="vertical">عمودي</option><option value="rtl">يمين إلى يسار</option><option value="ltr">يسار إلى يمين</option>
                    </select>
                    <Button disabled={edition.processing || !['draft', 'published'].includes(series.status)}>إنشاء الإصدار</Button>
                </form>
            </div>}

            {tab === 'chapters' && <div className="space-y-5">
                <div className={cardClass}>
                    <h2 className="font-bold">إصدار الفصول</h2>
                    <p className="text-sm text-muted-foreground">اختر الإصدار الذي ستضيف له الفصل قبل إدخال بياناته.</p>
                    <label className={labelClass} htmlFor="select_edition">إصدار الفصول</label>
                    <select id="select_edition" className={selectClass} value={currentEdition} onChange={e => { setCurrentEdition(e.target.value); setCurrentChapter(''); resetUpload(); }}>
                        {!editions.length && <option value="">أنشئ إصدارًا أولًا</option>}
                        {editions.map(ed => <option key={ed.id} value={ed.id}>{ed.label} — {ed.language}</option>)}
                    </select>
                    {chosenEdition && ['draft', 'published'].includes(chosenEdition.status) && <form className="grid gap-3 md:grid-cols-4" onSubmit={e => submit(e, () => chapter.post(`${prefix}/editions/${currentEdition}/chapters`, { preserveScroll: true }))}>
                        <div><label className={labelClass} htmlFor="chapter_num">رقم الفصل</label><Input id="chapter_num" value={chapter.data.display_number} onChange={e => chapter.setData('display_number', e.target.value)} required /></div>
                        <div><label className={labelClass} htmlFor="chapter_order">ترتيب رقمي</label><Input id="chapter_order" type="number" step="0.00000001" value={chapter.data.sort_key} onChange={e => chapter.setData('sort_key', e.target.value)} required /></div>
                        <div><label className={labelClass} htmlFor="chapter_title">العنوان</label><Input id="chapter_title" value={chapter.data.title} onChange={e => chapter.setData('title', e.target.value)} /></div>
                        <div><label className={labelClass} htmlFor="chapter_kind">نوع الفصل</label>
                            <select id="chapter_kind" className={selectClass} value={chapter.data.kind} onChange={e => chapter.setData('kind', e.target.value)}>
                                <option value="regular">عادي</option><option value="extra">إضافي</option><option value="prologue">مقدمة</option><option value="epilogue">خاتمة</option><option value="special">خاص</option>
                            </select></div>
                        <Button disabled={chapter.processing || !currentEdition}>إضافة فصل</Button>
                    </form>}
                </div>
                <div className={cardClass}>
                    <h2 className="font-bold">الفصول — اختيار للرفع والمعاينة</h2>
                    <p className="text-sm text-muted-foreground">اختر فصلًا لرفع صفحاته أو مراجعة حالته.</p>
                    {chapters.filter(ch => ch.edition_id === currentEdition).map(ch =>
                        <Button type="button" key={ch.id} variant={chosenChapter?.id === ch.id ? 'default' : 'outline'}
                            className={`admin-editor-chapter w-full justify-between text-right ${chosenChapter?.id === ch.id ? 'is-active' : ''}`} onClick={() => { setCurrentChapter(ch.id); resetUpload(); }}>
                            فصل {ch.display_number} · {statusLabels[ch.status] ?? ch.status} · {ch.revisions[0]?.pages.length ?? 0} صفحة
                        </Button>)}
                    {!chapters.some(ch => ch.edition_id === currentEdition) && <p>لا توجد فصول في الإصدار بعد.</p>}
                </div>
                {chosenChapter && <div className={cardClass}>
                    <h2 className="font-bold">الفصل {chosenChapter.display_number} — {chosenChapter.title ?? 'بدون عنوان'}</h2>
                    <p className="text-sm text-muted-foreground">اختَر ملف ZIP أو CBZ أو PDF أو صور JPG/PNG/WebP. كل رفع ينشئ مراجعة مسودة، ويُرتّب الصور طبيعيًا (1، 2، 10). يعتمد PDF على إعدادات المعالجة وحد الصفحات في السيرفر.</p>
                    <div className="grid gap-2 sm:grid-cols-2" role="group" aria-label="طريقة رفع الفصل">
                        <label className="flex items-center gap-2"><input type="radio" checked={chapterUploadMode === 'file'} onChange={() => { setChapterUploadMode('file'); resetUpload(); }} />ملف واحد: ZIP / CBZ / PDF / صورة</label>
                        <label className="flex items-center gap-2"><input type="radio" checked={chapterUploadMode === 'images'} onChange={() => { setChapterUploadMode('images'); resetUpload(); }} />مجموعة صور مرتبة</label>
                    </div>
                    <form className="space-y-3" onSubmit={e => submit(e, () => {
                        if (uploadValidation || pagesUpload.processing || !pagesUpload.data.import_request_key) return;
                        setUploadState('sending');
                        pagesUpload.post(`${prefix}/chapters/${chosenChapter.id}/upload`, {
                            forceFormData: true, preserveScroll: true,
                            onProgress: progress => setUploadState((progress?.percentage ?? 0) >= 100 ? 'processing' : 'sending'),
                            onSuccess: () => resetUpload(),
                            onError: () => setUploadState('error'),
                            onCancel: () => setUploadState('error'),
                        });
                    })}>
                        {chapterUploadMode === 'file' ? <label htmlFor="chapter_file" className={labelClass}>
                            اختر ZIP أو CBZ أو PDF أو صورة واحدة (حتى 60MB للملف، 12MB للصورة)
                            <Input key={`chapter-single-${inputVersion}`} id="chapter_file" type="file" required accept=".zip,.cbz,.pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp" onChange={e => chooseFile(e.currentTarget.files?.[0] ?? null)} />
                        </label> : <label htmlFor="chapter_images" className={labelClass}>
                            اختر صور JPG/PNG/WebP متعددة (حتى 200 صورة و60MB للمجموعة)
                            <Input key={`chapter-multiple-${inputVersion}`} id="chapter_images" type="file" multiple required accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" onChange={e => chooseImages(Array.from(e.currentTarget.files ?? []))} />
                        </label>}
                        {selectedBytes > 0 && <p className="text-sm text-muted-foreground">تم اختيار {chapterUploadMode === 'images' ? `${pagesUpload.data.images.length} صورة` : pagesUpload.data.upload?.name} · {formatMB(selectedBytes)}. سيُحفظ ترتيب الصور طبيعيًا.</p>}
                        {uploadValidation && <p role="alert" className="text-sm text-red-600">{uploadValidation}</p>}
                        {!!Object.keys(pagesUpload.errors).length && <Errors errors={pagesUpload.errors} />}
                        <div className="flex flex-wrap items-center gap-3">
                            <Button disabled={pagesUpload.processing || !!uploadValidation || !pagesUpload.data.import_request_key || (chapterUploadMode === 'file' ? !pagesUpload.data.upload : pagesUpload.data.images.length === 0)}>
                                {pagesUpload.processing ? 'جارٍ إرسال الملف…' : (async_imports_enabled ? 'رفع وإضافة لقائمة المعالجة' : 'رفع وإنشاء مراجعة للمعاينة')}
                            </Button>
                            {pagesUpload.processing && <Button type="button" variant="outline" onClick={() => pagesUpload.cancel()}>إلغاء الطلب</Button>}
                        </div>
                        {pagesUpload.processing && <div className="space-y-1" aria-live="polite">
                            <label htmlFor="chapter_upload_progress" className="text-sm">
                                {uploadState === 'processing' || pagesUpload.progress?.percentage === 100
                                    ? 'اكتمل إرسال الملف؛ السيرفر يعالج الصفحات الآن، لا تغلق الصفحة…'
                                    : `جارٍ إرسال الملفات: ${pagesUpload.progress?.percentage ?? 0}%`}
                            </label>
                            <progress id="chapter_upload_progress" className="h-3 w-full" value={pagesUpload.progress?.percentage ?? 0} max={100} />
                        </div>}
                        <p className="text-xs text-muted-foreground">رفع الملف لا ينشر الفصل. لو انقطع الاتصال، اضغط الرفع مجددًا بنفس الملفات بدون إعادة اختيارها؛ مفتاح الطلب يمنع إنشاء مراجعة مكررة. إلغاء الطلب بعد بدء معالجة السيرفر لا يضمن وقف المعالجة. تأكد من إعدادات PHP لحجم الملفات.</p>
                    </form>
                    {async_imports_enabled && <div className="admin-editor-note space-y-2 p-4" aria-live="polite">
                        <h3 className="font-semibold">معالجة الفصول بالخلفية</h3>
                        <p className="text-xs text-muted-foreground">بعد رفع الملف يمكنك مغادرة الصفحة؛ عامل Laravel يعالج الصور في الخلفية. لا يتم النشر تلقائيًا.</p>
                        {imports.filter(item => item.chapter_id === chosenChapter.id).length === 0 && <p className="text-sm">لا توجد عمليات رفع مسجلة لهذا الفصل.</p>}
                        {imports.filter(item => item.chapter_id === chosenChapter.id).map(item => <div key={item.id} className="admin-editor-choice text-sm">
                            <span>{item.status === 'queued' ? 'في الانتظار' : item.status === 'processing' ? 'جارٍ تجهيز الصفحات' : item.status === 'completed' ? 'اكتمل — راجع المراجعة الجديدة' : 'فشلت المعالجة'}</span>
                            {item.error_message && <p role="alert" className="mt-1 text-red-600">{item.error_message}</p>}
                        </div>)}
                    </div>}
                    {chosenChapter.revisions.map(rev => <div key={rev.id} className="admin-editor-page space-y-3 p-3">
                        <p className="font-bold">مراجعة #{rev.revision_no} — {statusLabels[rev.status] ?? rev.status} — {rev.pages.length} صفحة</p>
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-6">
                            {rev.pages.map((p, i) => <div key={p.id} className="admin-editor-page p-2">
                                <a href={p.url} target="_blank" rel="noreferrer"><img src={p.url} alt={`صفحة ${p.index}`} loading="lazy" className="h-40 w-full object-contain" /></a>
                                <div className="mt-1 text-xs">صفحة {p.index} · {p.width}×{p.height}</div>
                                {rev.status === 'draft' && <div className="flex gap-1">
                                    <Button size="sm" type="button" variant="outline" disabled={i === 0} onClick={() => {
                                        const ids = rev.pages.map(x => x.id); [ids[i - 1], ids[i]] = [ids[i], ids[i - 1]];
                                        router.post(`${prefix}/revisions/${rev.id}/reorder`, { page_ids: ids }, { preserveScroll: true });
                                    }}>↑</Button>
                                    <Button size="sm" type="button" variant="outline" disabled={i === rev.pages.length - 1} onClick={() => {
                                        const ids = rev.pages.map(x => x.id); [ids[i + 1], ids[i]] = [ids[i], ids[i + 1]];
                                        router.post(`${prefix}/revisions/${rev.id}/reorder`, { page_ids: ids }, { preserveScroll: true });
                                    }}>↓</Button>
                                </div>}
                            </div>)}
                        </div>
                    </div>)}
                    <div className="admin-editor-note space-y-2 p-4">
                        <h3 className="font-bold">فحص جاهزية النشر — آخر حساب للصفحة</h3>
                        <Link href={`${prefix}/chapters/${chosenChapter.id}/readiness`} target="_blank" className="text-sm underline">عرض نتيجة الفحص الحالية (JSON)</Link>
                        {chosenChapter.issues.length ? chosenChapter.issues.map((issue, i) => <p className="text-sm text-red-500" key={i}>{issue}</p>)
                            : <p className="text-sm text-green-600">لا توجد مشاكل معروفة حاليًا؛ سيتم فحص كل شيء مجددًا لحظة النشر.</p>}
                        {revision && <Button disabled={chosenChapter.issues.length > 0} onClick={() => {
                            if (window.confirm('هل راجعت ترتيب الصفحات وملكية العمل أو حقوق نشره وتريد نشر هذه المراجعة؟')) {
                                router.post(`${prefix}/chapters/${chosenChapter.id}/publish`, { revision_id: revision.id }, { preserveScroll: true });
                            }
                        }}>نشر المراجعة #{revision.revision_no}</Button>}
                    </div>
                </div>}
            </div>}

            {tab === 'rights' && <div className="grid gap-5 lg:grid-cols-2">
                <div className={cardClass}>
                    <h2 className="font-bold">ملكية العمل الأصلي — من غير مستندات ترخيص خارجي</h2>
                    {series.is_original_content ? <div className="space-y-3">
                        <p className="text-sm text-green-600">تم تأكيد ملكية هذا العمل الأصلي من حساب الأدمن. يمكنك نشر فصوله بعد فحص سلامة الغلاف والصور.</p>
                        <Button type="button" variant="outline" className="admin-editor-danger-action" onClick={() => {
                            if (window.confirm('هل تريد إلغاء تأكيد ملكية العمل؟ قد تختفي الفصول المنشورة فورًا من التطبيق.')) {
                                router.post(`${prefix}/ownership/revoke`, { confirm_revoke: true }, { preserveScroll: true });
                            }
                        }}>إلغاء اعتماد العمل الأصلي</Button>
                    </div> : <div className="space-y-3">
                        <p className="text-sm text-muted-foreground">لو العمل والرسومات وحقوق إصداراته من إنتاجك، أكّد ده مرة واحدة فقط. بعد كده مش محتاج ترفع وثيقة تصريح لكل فصل. لو العمل لطرف آخر، استخدم تبويب الحقوق والتصاريح.</p>
                        <Button type="button" onClick={() => {
                            if (window.confirm('أؤكد أن العمل ورسوماته من إنتاجي وأنني أملك حق نشر جميع الإصدارات والفصول المضافة إليه.')) {
                                router.post(`${prefix}/ownership/original`, { confirm_original: true }, { preserveScroll: true });
                            }
                        }}>أؤكد أن هذا عملي الأصلي وأملك حق نشره</Button>
                    </div>}
                </div>
                <form className={cardClass} onSubmit={e => submit(e, () => license.post(`${prefix}/rights/licenses`, { forceFormData: true, preserveScroll: true }))}>
                    <h2 className="font-bold">1 — مستند تصريح النشر (خاص)</h2>
                    <p className="text-sm text-muted-foreground">يجب أن تملك حق إعادة توزيع الصور والترجمة وليس مجرد امتلاك نسخة منها.</p>
                    <label className={labelClass} htmlFor="holder">صاحب الحق</label>
                    <Input id="holder" required value={license.data.holder_name} onChange={e => license.setData('holder_name', e.target.value)} />
                    <label className={labelClass} htmlFor="rights_start">بداية التصريح (UTC)</label>
                    <Input id="rights_start" type="date" required value={license.data.valid_from} onChange={e => license.setData('valid_from', e.target.value)} />
                    <label className={labelClass} htmlFor="rights_end">نهاية التصريح (UTC)</label>
                    <Input id="rights_end" type="date" required value={license.data.valid_until} onChange={e => license.setData('valid_until', e.target.value)} />
                    <label className={labelClass} htmlFor="proof">مستند PDF أو JPG أو PNG حتى 10MB</label>
                    <Input id="proof" required type="file" accept=".pdf,image/jpeg,image/png" onChange={e => license.setData('document', e.currentTarget.files?.[0] ?? null)} />
                    <Button disabled={license.processing || !license.data.document}>رفع المستند للمراجعة</Button>
                </form>
                <div className={cardClass}>
                    <h2 className="font-bold">2 — مراجعة التصريح واعتماده صراحة</h2>
                    {licenses.length === 0 && <p className="admin-editor-note p-4 text-sm">لا توجد مستندات مسجلة.</p>}
                    {licenses.map(l => <div key={l.id} className="admin-editor-page space-y-2 p-3 text-sm">
                        <p>{l.holder_name} — {statusLabels[l.status] ?? l.status} — <a className="underline" target="_blank" rel="noreferrer" href={`${prefix}/rights/licenses/${l.id}/proof`}>عرض مستند الترخيص خاص بالأدمن</a></p>
                        <p dir="ltr">{l.valid_from} → {l.valid_until}</p>
                        {l.status === 'pending' && <>
                            <label className="flex gap-2"><input type="checkbox" checked={acceptProof} onChange={e => setAcceptProof(e.target.checked)} />أؤكد فحص المستند ووجود حق توزيع وترجمة هذا العمل</label>
                            <Button type="button" disabled={!acceptProof} onClick={() => router.post(`${prefix}/rights/licenses/${l.id}/approve`, { confirmed_rights: true }, { preserveScroll: true })}>اعتماد الترخيص</Button>
                        </>}
                    </div>)}
                </div>
                <form className={cardClass} onSubmit={e => submit(e, () => grant.post(`${prefix}/rights/grants`, { preserveScroll: true }))}>
                    <h2 className="font-bold">3 — ربط الترخيص بإصدار ولغة ومنطقة</h2>
                    <label className={labelClass} htmlFor="grant_license">ترخيص معتمد</label>
                    <select id="grant_license" required className={selectClass} value={grant.data.license_id} onChange={e => grant.setData('license_id', e.target.value)}>
                        <option value="">اختر الترخيص</option>{licenses.filter(l => l.status === 'approved').map(l => <option value={l.id} key={l.id}>{l.holder_name}</option>)}
                    </select>
                    <label className={labelClass} htmlFor="grant_edition">الإصدار</label>
                    <select id="grant_edition" required className={selectClass} value={grant.data.edition_id} onChange={e => grant.setData('edition_id', e.target.value)}>
                        <option value="">اختر الإصدار</option>{editions.map(ed => <option key={ed.id} value={ed.id}>{ed.label} — {ed.language}</option>)}
                    </select>
                    <label className={labelClass} htmlFor="territory">المنطقة المصرّح بها: EG مثلًا (GLOBAL عند وجود سند صريح فقط)</label>
                    <Input id="territory" dir="ltr" required maxLength={6} value={grant.data.territory_code} onChange={e => grant.setData('territory_code', e.target.value.toUpperCase())} />
                    <label className="flex gap-2"><input type="checkbox" checked={grant.data.can_translate} onChange={e => grant.setData('can_translate', e.target.checked)} />التصريح يسمح بترجمة هذا الإصدار</label>
                    <Button disabled={grant.processing || !grant.data.license_id || !grant.data.edition_id}>تفعيل نطاق التصريح</Button>
                </form>
                <div className={cardClass}>
                    <h2 className="font-bold">التصاريح المربوطة</h2>
                    {grants.length === 0 && <p className="admin-editor-note p-4 text-sm">لا توجد تصاريح مربوطة بهذا العمل بعد.</p>}
                    {grants.map(g => <p key={g.id} className="admin-editor-choice text-sm">{statusLabels[g.status] ?? g.status}: {g.languages.join(', ')} · {g.territories.join(', ')}</p>)}
                    {series.status === 'published' && <div className="rounded border p-3">
                        <p className="mb-3 text-sm text-red-500">سحب النشر يوقف العرض العام فورًا مع الاحتفاظ بالسجل.</p>
                        <Button variant="destructive" type="button" onClick={() => {
                            if (window.confirm('تأكيد إيقاف نشر هذا العمل بالكامل؟')) {
                                router.post(`${prefix}/withdraw`, { confirm: 'WITHDRAW' });
                            }
                        }}>سحب نشر العمل</Button>
                    </div>}
                </div>
            </div>}
        </section>
    </>;
}
