import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FormEvent, useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Alias = { locale: string; value: string };
type Series = {
    id: string; slug: string; primary_title: string; format: string; original_language: string;
    completion_status: string; synopsis: string; genre_codes: string[]; tag_codes: string[];
    author_name: string; illustrator_name: string; age_rating: string;
    aliases: Alias[]; cover_url: string | null;
};
type CodeItem = { code: string; name: string };
type FormData = {
    slug: string; primary_title: string; format: string; original_language: string;
    completion_status: string; synopsis: string; author_name: string;
    illustrator_name: string; age_rating: string;
    aliases: Alias[]; genre_codes: string[]; tag_codes: string[];
    new_genre_code: string; new_genre_name: string;
    new_tag_code: string; new_tag_name: string;
};

const languageOptions = [
    ['ar', 'العربية'], ['ja', 'اليابانية'], ['ko', 'الكورية'], ['zh', 'الصينية'],
    ['en', 'الإنجليزية'], ['other', 'لغة أخرى'],
] as const;
const formatLabels: Record<string, string> = { manga: 'مانجا', manhwa: 'مانهوا', manhua: 'مانهوا صينية', webtoon: 'ويب تون' };
const cardClass = 'admin-card space-y-4 p-4 md:p-6';
const selectClass = 'h-10 w-full rounded-md border bg-background px-3 text-sm';
const transliterate: Record<string, string> = {
    'ا': 'a', 'أ': 'a', 'إ': 'i', 'آ': 'a', 'ب': 'b', 'ت': 't', 'ث': 'th',
    'ج': 'j', 'ح': 'h', 'خ': 'kh', 'د': 'd', 'ذ': 'dh', 'ر': 'r', 'ز': 'z',
    'س': 's', 'ش': 'sh', 'ص': 's', 'ض': 'd', 'ط': 't', 'ظ': 'z', 'ع': 'a',
    'غ': 'gh', 'ف': 'f', 'ق': 'q', 'ك': 'k', 'ل': 'l', 'م': 'm', 'ن': 'n',
    'ه': 'h', 'ة': 'h', 'و': 'w', 'ؤ': 'w', 'ي': 'y', 'ى': 'a', 'ئ': 'y', 'ء': '',
};
function suggestSlug(title: string): string {
    return title.toLowerCase().normalize('NFKD')
        .replace(/[\u064b-\u065f\u0670]/g, '')
        .replace(/[\u0621-\u064a]/g, letter => transliterate[letter] ?? '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 180).replace(/-+$/, '');
}

export default function SeriesForm({ series, genres, tags }: { series: Series | null; genres: CodeItem[]; tags: CodeItem[] }) {
    const { props } = usePage();
    const flash = props.success as string | null | undefined;
    const [tab, setTab] = useState<'info' | 'editions' | 'chapters' | 'rights'>('info');
    const [slugEdited, setSlugEdited] = useState(!!series);
    const [customLanguage, setCustomLanguage] = useState(
        !!series && !languageOptions.some(([code]) => code === series.original_language),
    );
    const form = useForm<FormData>({
        primary_title: series?.primary_title ?? '',
        slug: series?.slug ?? '',
        format: series?.format ?? 'manga',
        original_language: series?.original_language ?? 'ja',
        completion_status: series?.completion_status ?? 'ongoing',
        synopsis: series?.synopsis ?? '',
        author_name: series?.author_name ?? '',
        illustrator_name: series?.illustrator_name ?? '',
        age_rating: series?.age_rating ?? '',
        aliases: series?.aliases ?? [],
        genre_codes: series?.genre_codes ?? [],
        tag_codes: series?.tag_codes ?? [],
        new_genre_code: '', new_genre_name: '', new_tag_code: '', new_tag_name: '',
    });
    const cover = useForm<{ upload: File | null }>({ upload: null });
    const [localCover, setLocalCover] = useState<string | null>(null);
    useEffect(() => {
        if (!cover.data.upload) { setLocalCover(null); return; }
        const url = URL.createObjectURL(cover.data.upload);
        setLocalCover(url);
        return () => URL.revokeObjectURL(url);
    }, [cover.data.upload]);

    const onTitleChange = (value: string) => {
        form.setData(previous => ({
            ...previous,
            primary_title: value,
            slug: slugEdited ? previous.slug : suggestSlug(value),
        }));
    };
    const choose = (key: 'genre_codes' | 'tag_codes', code: string, checked: boolean) => {
        form.setData(key, checked
            ? [...form.data[key], code]
            : form.data[key].filter(current => current !== code));
    };
    const fieldError = (field: string) => {
        const message = (form.errors as Record<string, string | undefined>)[field];
        return message ? <p role="alert" className="mt-1 text-sm text-destructive">{message}</p> : null;
    };
    const onSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (form.processing) return;
        if (series) form.put(`/admin/series/${series.id}`, { preserveScroll: true });
        else form.post('/admin/series');
    };
    const addAlias = () => {
        if (form.data.aliases.length < 8) form.setData('aliases', [...form.data.aliases, { locale: 'ar', value: '' }]);
    };
    const updateAlias = (index: number, key: keyof Alias, value: string) => {
        form.setData('aliases', form.data.aliases.map((alias, i) => i === index ? { ...alias, [key]: value } : alias));
    };
    const saveCover = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (series && cover.data.upload && !cover.processing && cover.data.upload.size <= 12 * 1024 * 1024) {
            cover.post(`/admin/series/${series.id}/cover`, {
                forceFormData: true, preserveScroll: true,
                onSuccess: () => cover.reset(),
            });
        }
    };

    return <>
        <Head title={series ? `تعديل بيانات ${series.primary_title}` : 'إضافة عمل جديد'} />
        <section dir="rtl" className="admin-editor mx-auto w-full max-w-6xl space-y-6 px-4 py-6 md:px-8">
            <header className="admin-editor-hero space-y-3">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <span className="admin-editor-kicker">استوديو الأعمال · ٠١ / ٠٤</span>
                    <Link href="/admin/series" className="text-sm font-semibold underline underline-offset-4">← كل الأعمال</Link>
                </div>
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1>{series ? series.primary_title : 'إضافة عمل جديد'}</h1>
                        <p className="mt-2 text-sm">
                            {series ? <><bdi dir="ltr">{series.slug}</bdi> · {formatLabels[series.format] ?? series.format}</> : 'مانجا · مانهوا · ويب تون'}
                        </p>
                    </div>
                    {series && <Link className="rounded-xl border border-white/25 bg-white/10 px-4 py-2 text-sm font-semibold" href={`/admin/series/${series.id}/content`}>إدارة المحتوى والنشر ←</Link>}
                </div>
                <p className="text-sm">ابدأ بالعنوان وبيانات العمل، ثم احفظه كمسودة لتفعيل الغلاف والإصدارات والفصول والحقوق.</p>
                <span className="admin-editor-state">مسودة خاصة · لا تظهر للقراء قبل النشر</span>
            </header>
            {flash && <p role="status" className="admin-editor-message admin-editor-message--success">{flash}</p>}
            {Object.keys(form.errors).length > 0 && <div role="alert" className="admin-editor-message admin-editor-message--error space-y-1">
                <p className="font-semibold">راجع أخطاء البيانات قبل الحفظ:</p>
                {Object.entries(form.errors).map(([field, message]) => <p key={field}>{field}: {message}</p>)}
            </div>}

            <nav aria-label="أقسام إدارة العمل" className="admin-editor-tabs grid grid-cols-2 gap-2 sm:flex sm:flex-wrap">
                <Button type="button" variant={tab === 'info' ? 'default' : 'outline'}
                    className="h-auto min-h-11 gap-2 whitespace-normal px-2 py-2 text-xs sm:text-sm"
                    aria-current={tab === 'info' ? 'page' : undefined} onClick={() => setTab('info')}><span className="admin-editor-tab-number">٠١</span>بيانات العمل والغلاف</Button>
                {([
                    ['editions', 'الإصدارات', '٠٢'], ['chapters', 'الفصول والصفحات', '٠٣'], ['rights', 'الحقوق والنشر', '٠٤'],
                ] as const).map(([key, label, number]) => series
                    ? <Link key={key} className="inline-flex min-h-11 items-center justify-center gap-2 rounded-md border px-2 py-2 text-center text-xs font-medium hover:bg-muted sm:px-4 sm:text-sm" href={`/admin/series/${series.id}/content?tab=${key}`}><span className="admin-editor-tab-number">{number}</span>{label}</Link>
                    : <Button type="button" key={key} variant={tab === key ? 'default' : 'outline'}
                        className="h-auto min-h-11 gap-2 whitespace-normal px-2 py-2 text-xs sm:text-sm"
                        aria-current={tab === key ? 'page' : undefined} onClick={() => setTab(key)}><span className="admin-editor-tab-number">{number}</span>{label}</Button>)}
            </nav>

            {!series && tab !== 'info' && <div role="status" className="admin-editor-note flex flex-wrap items-center justify-between gap-3 p-4 text-sm">
                <p>احفظ بيانات العمل كمسودة أولًا لتفعيل الإصدار والفصول ورفع الملفات ومراجعة الحقوق.</p>
                <Button type="button" variant="outline" onClick={() => setTab('info')}>الرجوع لبيانات العمل والحفظ</Button>
            </div>}

            {!series && tab === 'editions' && <div className="grid gap-5 lg:grid-cols-2">
                <section className={cardClass}>
                    <h2 className="font-bold">الإصدارات الحالية</h2>
                    <p className="text-sm text-muted-foreground">لا توجد إصدارات بعد؛ ده عمل جديد لم يُحفظ.</p>
                </section>
                <section className={cardClass}>
                    <h2 className="font-bold">إنشاء إصدار جديد كمسودة</h2>
                    <div className="space-y-2"><Label htmlFor="draft_edition_label">اسم الإصدار</Label>
                        <Input id="draft_edition_label" value="النسخة العربية" disabled readOnly /></div>
                    <div className="space-y-2"><Label htmlFor="draft_edition_language">لغة الإصدار</Label>
                        <Input id="draft_edition_language" value="ar" dir="ltr" disabled readOnly /></div>
                    <div className="space-y-2"><Label htmlFor="draft_edition_direction">اتجاه القراءة</Label>
                        <select id="draft_edition_direction" className={selectClass} disabled defaultValue="auto">
                            <option value="auto">تلقائي</option>
                        </select></div>
                    <p className="text-sm text-muted-foreground">بعد الحفظ هتقدر تعدّل القيم دي وتنشئ إصدارًا أو أكثر، من غير نشر تلقائي.</p>
                    <Button type="button" disabled>احفظ المسودة أولًا لإنشاء الإصدار</Button>
                </section>
            </div>}

            {!series && tab === 'chapters' && <div className="space-y-5">
                <section className={cardClass}>
                    <h2 className="font-bold">الفصول — اختيار الإصدار</h2>
                    <Label htmlFor="draft_chapter_edition">إصدار الفصول</Label>
                    <select id="draft_chapter_edition" className={selectClass} disabled defaultValue="none">
                        <option value="none">احفظ العمل، ثم أنشئ إصدارًا أولًا</option>
                    </select>
                    <div className="grid gap-3 md:grid-cols-4">
                        <div className="space-y-2"><Label htmlFor="draft_chapter_number">رقم الفصل</Label><Input id="draft_chapter_number" disabled placeholder="1" /></div>
                        <div className="space-y-2"><Label htmlFor="draft_chapter_sort">ترتيب رقمي</Label><Input id="draft_chapter_sort" disabled placeholder="1" /></div>
                        <div className="space-y-2"><Label htmlFor="draft_chapter_title">العنوان</Label><Input id="draft_chapter_title" disabled placeholder="عنوان الفصل" /></div>
                        <div className="space-y-2"><Label htmlFor="draft_chapter_kind">نوع الفصل</Label>
                            <select id="draft_chapter_kind" className={selectClass} disabled defaultValue="regular"><option value="regular">عادي</option></select></div>
                    </div>
                    <Button type="button" disabled>إضافة فصل</Button>
                </section>
                <section className={cardClass}>
                    <h2 className="font-bold">الفصول — اختيار للرفع والمعاينة</h2>
                    <p className="text-sm text-muted-foreground">هنا هتظهر الفصول بعد حفظ المسودة وإنشاء إصدار.</p>
                </section>
                <section className={cardClass}>
                    <h2 className="font-bold">رفع صور الفصل ومعاينة الصفحات</h2>
                    <p className="text-sm text-muted-foreground">بعد إضافة فصل تقدر ترفع ZIP أو CBZ أو PDF أو صور متعددة وتتابع المعالجة بالخلفية. المراجعة بتفضل مسودة لحد الموافقة على النشر.</p>
                    <Button type="button" disabled>احفظ المسودة وأضف إصدارًا وفصلًا أولًا</Button>
                </section>
            </div>}

            {!series && tab === 'rights' && <div className="grid gap-5 lg:grid-cols-2">
                <section className={cardClass}>
                    <h2 className="font-bold">مستندات التصريح ومراجعتها</h2>
                    <p className="text-sm text-muted-foreground">لو العمل مش من إنتاجك، تضيف تصريح نشر حقيقي بعد حفظ العمل. التأكيد على الملكية الأصلية متاح فقط لو فعلًا تملك العمل ورسوماته.</p>
                    <div className="space-y-2"><Label htmlFor="draft_right_holder">صاحب الحق</Label><Input id="draft_right_holder" disabled /></div>
                    <div className="space-y-2"><Label htmlFor="draft_right_start">بداية التصريح (UTC)</Label><Input id="draft_right_start" type="date" disabled /></div>
                    <div className="space-y-2"><Label htmlFor="draft_right_end">نهاية التصريح (UTC)</Label><Input id="draft_right_end" type="date" disabled /></div>
                    <Button type="button" disabled>رفع المستند للمراجعة</Button>
                </section>
                <section className={cardClass}>
                    <h2 className="font-bold">التراخيص المرتبطة</h2>
                    <p className="text-sm text-muted-foreground">لا يوجد تصريح مرتبط بالعمل الجديد حتى الآن.</p>
                </section>
                <section className={cardClass}>
                    <h2 className="font-bold">ربط الترخيص بإصدار ولغة ومنطقة</h2>
                    <p className="text-sm text-muted-foreground">بعد حفظ المسودة وإنشاء إصدار، تقدر تختار الترخيص واللغة والمنطقة المسموح بها.</p>
                    <Button type="button" disabled>تفعيل نطاق التصريح</Button>
                </section>
                <section className={cardClass}>
                    <h2 className="font-bold">النشر وسحب النشر</h2>
                    <p className="text-sm text-muted-foreground">العمل حاليًا غير منشور. النشر لا يتم بمجرد الحفظ أو اختيار الترخيص، ولازم ينجح فحص الحقوق وجاهزية الصفحات.</p>
                    <Button type="button" disabled>نشر العمل</Button>
                </section>
            </div>}

            {tab === 'info' && <>
            <div className="grid gap-5 lg:grid-cols-2">
                <section className={cardClass} aria-label="الغلاف الرئيسي">
                    <h2 className="font-bold">الغلاف الرئيسي — تخزين خاص</h2>
                    <p className="text-sm text-muted-foreground">الصورة التي ستظهر للعمل في المكتبة. يمكنك رفعها بعد حفظ المسودة.</p>
                    {(localCover || series?.cover_url)
                        ? <img src={localCover || series?.cover_url || ''} alt="معاينة غلاف العمل" className="admin-editor-cover-image max-h-72 max-w-full rounded object-contain" />
                        : <div className="admin-editor-cover-placeholder flex min-h-40 items-center justify-center rounded-lg border border-dashed p-5 text-center text-sm text-muted-foreground">لم يتم رفع غلاف بعد</div>}
                    {series
                        ? <form onSubmit={saveCover} className="space-y-3">
                            <Label htmlFor="cover_upload">اختر صورة JPG/PNG/WebP لا تتجاوز 12 ميجابايت</Label>
                            <Input id="cover_upload" type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" onChange={e => cover.setData('upload', e.target.files?.[0] ?? null)} />
                            {(cover.data.upload?.size ?? 0) > 12 * 1024 * 1024 && <p role="alert" className="text-sm text-destructive">حجم الغلاف أكبر من 12MB.</p>}
                            {Object.entries(cover.errors).map(([field, error]) => <p role="alert" key={field} className="text-sm text-destructive">{error}</p>)}
                            <Button type="submit" disabled={!cover.data.upload || cover.processing || (cover.data.upload?.size ?? 0) > 12 * 1024 * 1024}>{cover.processing ? 'جارٍ رفع الغلاف…' : 'رفع الغلاف'}</Button>
                        </form>
                        : <p className="text-sm text-muted-foreground">الغلاف بيتفعّل هنا بعد الضغط على «حفظ العمل كمسودة» لأن الصور لازم ترتبط بعمل محفوظ. مش محتاج تنشئ عمل تاني.</p>}
                </section>

                <form onSubmit={onSubmit} className={cardClass} noValidate>
                    <h2 className="font-bold">بيانات المؤلف والرسام والفئة العمرية</h2>
                    <p className="text-sm text-muted-foreground">اسم العمل هو الحقل المطلوب للبدء. باقي التفاصيل تقدر تكملها الآن أو قبل النشر.</p>
                    <div className="space-y-2"><Label htmlFor="primary_title">اسم العمل الرئيسي *</Label>
                        <Input id="primary_title" required maxLength={300} value={form.data.primary_title} onChange={e => onTitleChange(e.target.value)} placeholder="مثال: رحلة القمر" />{fieldError('primary_title')}</div>
                    <div className="space-y-2"><Label htmlFor="author_name">المؤلف</Label>
                        <Input id="author_name" maxLength={200} value={form.data.author_name} onChange={e => form.setData('author_name', e.target.value)} placeholder="اسم المؤلف" />{fieldError('author_name')}</div>
                    <div className="space-y-2"><Label htmlFor="illustrator_name">الرسام</Label>
                        <Input id="illustrator_name" maxLength={200} value={form.data.illustrator_name} onChange={e => form.setData('illustrator_name', e.target.value)} placeholder="اسم الرسام" />{fieldError('illustrator_name')}</div>
                    <div className="space-y-2"><Label htmlFor="age_rating">الفئة العمرية</Label>
                        <select id="age_rating" className={selectClass} value={form.data.age_rating} onChange={e => form.setData('age_rating', e.target.value)}>
                            <option value="">غير محددة</option><option value="all">مناسب للجميع</option><option value="12+">12+</option><option value="16+">16+</option><option value="18+">18+</option>
                        </select>{fieldError('age_rating')}</div>
                    <details className="rounded-lg border p-3">
                        <summary className="cursor-pointer text-sm font-medium">خيارات إضافية: النوع واللغة والوصف والتصنيفات والوسوم</summary>
                        <div className="mt-4 space-y-5">
                            <div className="grid gap-4 md:grid-cols-2">
                                <div className="space-y-2"><Label htmlFor="slug">الرابط المختصر (Slug) *</Label>
                                    <Input id="slug" required dir="ltr" maxLength={180} pattern="[a-z0-9]+(-[a-z0-9]+)*" value={form.data.slug} onChange={e => { setSlugEdited(true); form.setData('slug', e.target.value); }} placeholder="moon-journey" />
                                    <p className="text-xs text-muted-foreground">اقتراح تلقائي قابل للتعديل؛ حروف إنجليزية صغيرة وأرقام وشرطة فقط.</p>{fieldError('slug')}</div>
                                <div className="space-y-2"><Label htmlFor="format">نوع العمل *</Label>
                                    <select id="format" className={selectClass} value={form.data.format} onChange={e => form.setData('format', e.target.value)}>
                                        <option value="manga">مانجا (Manga)</option><option value="manhwa">مانهوا (Manhwa)</option><option value="manhua">مانهوا صينية (Manhua)</option><option value="webtoon">ويب تون (Webtoon)</option>
                                    </select>{fieldError('format')}</div>
                                <div className="space-y-2"><Label htmlFor="original_language">لغة العمل الأصلية *</Label>
                                    <select id="original_language" className={selectClass} value={customLanguage ? 'other' : form.data.original_language}
                                        onChange={e => { setCustomLanguage(e.target.value === 'other'); form.setData('original_language', e.target.value === 'other' ? '' : e.target.value); }}>
                                        {languageOptions.map(([code, label]) => <option key={code} value={code}>{label}</option>)}
                                    </select>
                                    {customLanguage && <Input className="mt-2" aria-label="كود اللغة الأصلية" dir="ltr" placeholder="fr" value={form.data.original_language} onChange={e => form.setData('original_language', e.target.value)} maxLength={24} />}
                                    {fieldError('original_language')}</div>
                                <div className="space-y-2"><Label htmlFor="completion_status">حالة القصة *</Label>
                                    <select id="completion_status" className={selectClass} value={form.data.completion_status} onChange={e => form.setData('completion_status', e.target.value)}>
                                        <option value="ongoing">مستمر</option><option value="completed">مكتمل</option><option value="hiatus">متوقف مؤقتًا</option><option value="cancelled">ملغى</option>
                                    </select>{fieldError('completion_status')}</div>
                            </div>
                            <div className="space-y-2"><Label htmlFor="synopsis">وصف العمل</Label>
                                <textarea id="synopsis" rows={5} maxLength={10000} className="w-full rounded-md border bg-background p-3 text-sm" value={form.data.synopsis} onChange={e => form.setData('synopsis', e.target.value)} placeholder="نبذة عن القصة بدون حرق للأحداث" />{fieldError('synopsis')}</div>
                            <div className="space-y-3 rounded-lg border p-4"><div className="flex flex-wrap items-center justify-between gap-2"><h3 className="font-bold">الأسماء البديلة</h3><Button type="button" variant="outline" disabled={form.data.aliases.length >= 8} onClick={addAlias}>+ إضافة اسم</Button></div>
                                <p className="text-xs text-muted-foreground">اختياري، حتى 8 أسماء بلغات مختلفة.</p>
                                {form.data.aliases.map((alias, index) => <div key={index} className="grid items-end gap-2 sm:grid-cols-[120px_1fr_auto]">
                                    <div><Label htmlFor={`alias_locale_${index}`}>اللغة</Label><Input id={`alias_locale_${index}`} dir="ltr" maxLength={24} value={alias.locale} onChange={e => updateAlias(index, 'locale', e.target.value)} />{fieldError(`aliases.${index}.locale`)}</div>
                                    <div><Label htmlFor={`alias_value_${index}`}>الاسم البديل</Label><Input id={`alias_value_${index}`} maxLength={300} value={alias.value} onChange={e => updateAlias(index, 'value', e.target.value)} />{fieldError(`aliases.${index}.value`)}</div>
                                    <Button type="button" variant="outline" onClick={() => form.setData('aliases', form.data.aliases.filter((_, i) => i !== index))}>حذف</Button>
                                </div>)}{fieldError('aliases')}
                            </div>
                            <fieldset className="space-y-4 rounded-lg border p-4"><legend className="px-2 font-bold">التصنيفات</legend>
                                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">{genres.map(genre => <label key={genre.code} className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.genre_codes.includes(genre.code)} onChange={e => choose('genre_codes', genre.code, e.target.checked)} /><span>{genre.name}</span></label>)}</div>
                                {genres.length === 0 && <p className="text-sm text-muted-foreground">لا توجد تصنيفات محفوظة؛ أضف أول تصنيف بالأسفل.</p>}
                                {fieldError('genre_codes')}
                                <div className="grid gap-3 border-t pt-4 sm:grid-cols-2"><div><Label htmlFor="new_genre_code">رمز تصنيف جديد (اختياري)</Label><Input id="new_genre_code" dir="ltr" maxLength={80} placeholder="fantasy" value={form.data.new_genre_code} onChange={e => form.setData('new_genre_code', e.target.value)} />{fieldError('new_genre_code')}</div>
                                    <div><Label htmlFor="new_genre_name">اسم التصنيف الجديد</Label><Input id="new_genre_name" maxLength={140} placeholder="فانتازيا" value={form.data.new_genre_name} onChange={e => form.setData('new_genre_name', e.target.value)} />{fieldError('new_genre_name')}</div></div>
                            </fieldset>
                            <fieldset className="space-y-4 rounded-lg border p-4"><legend className="px-2 font-bold">الوسوم</legend>
                                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">{tags.map(tag => <label key={tag.code} className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.tag_codes.includes(tag.code)} onChange={e => choose('tag_codes', tag.code, e.target.checked)} /><span>{tag.name}</span></label>)}</div>
                                {tags.length === 0 && <p className="text-sm text-muted-foreground">لا توجد وسوم محفوظة؛ يمكنك إنشاء أول وسم.</p>}
                                {fieldError('tag_codes')}
                                <div className="grid gap-3 border-t pt-4 sm:grid-cols-2"><div><Label htmlFor="new_tag_code">رمز وسم جديد (اختياري)</Label><Input id="new_tag_code" dir="ltr" maxLength={80} placeholder="adventure" value={form.data.new_tag_code} onChange={e => form.setData('new_tag_code', e.target.value)} />{fieldError('new_tag_code')}</div>
                                    <div><Label htmlFor="new_tag_name">اسم الوسم</Label><Input id="new_tag_name" maxLength={140} placeholder="مغامرات" value={form.data.new_tag_name} onChange={e => form.setData('new_tag_name', e.target.value)} />{fieldError('new_tag_name')}</div></div>
                            </fieldset>
                        </div>
                    </details>
                    <div className="admin-editor-note space-y-3 p-4">
                        <p className="text-xs">الحفظ كمسودة لا ينشر العمل. بعد الحفظ تقدر ترفع الغلاف والإصدارات والفصول من التبويبات.</p>
                        <Button className="w-full sm:w-auto" type="submit" disabled={form.processing}>{form.processing ? 'جارٍ الحفظ…' : series ? 'حفظ البيانات' : 'حفظ العمل كمسودة'}</Button>
                    </div>
                </form>
            </div>


            </>}
        </section>
    </>;
}
