import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Row = {
    id: string; slug: string; primary_title: string; format: string; publication_status: string;
    completion_status: string; published_chapters: number; cover_url: string | null; updated_at: string;
};
type Paginator = {
    data: Row[]; current_page: number; last_page: number; total: number;
    prev_page_url: string | null; next_page_url: string | null;
};
type Filters = { search: string; format: string; status: string; completion: string };
const selectClass = 'h-10 w-full rounded-md border bg-background px-3 text-sm';
const formats: Record<string, string> = { manga: 'مانجا', manhwa: 'مانهوا', manhua: 'مانهوا صينية', webtoon: 'ويب تون' };
const states: Record<string, string> = { draft: 'مسودة', reviewed: 'قيد المراجعة', published: 'منشور', archived: 'مؤرشف', revoked: 'مسحوب' };
const completion: Record<string, string> = { ongoing: 'مستمر', completed: 'مكتمل', hiatus: 'متوقف مؤقتًا', cancelled: 'ملغى' };

export default function SeriesIndex({ series, filters }: { series: Paginator; filters: Filters }) {
    const [query, setQuery] = useState<Filters>(filters);
    const onSearch = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        router.get('/admin/series', query, { preserveState: true, replace: true });
    };
    const reset = () => {
        setQuery({ search: '', format: '', status: '', completion: '' });
        router.get('/admin/series', {}, { replace: true });
    };
    return <>
        <Head title="إدارة الأعمال والمحتوى" />
        <section dir="rtl" className="flex flex-1 flex-col gap-6 p-4 md:p-8">
            <header className="flex flex-wrap items-center justify-between gap-4">
                <div><h1 className="text-2xl font-bold md:text-3xl">إدارة المانجا والمانهوا</h1>
                    <p className="mt-2 text-sm text-muted-foreground">كل الأعمال والمسودات والأغلفة والفصول في مكان واحد. النشر يحتاج مراجعة الحقوق والجاهزية.</p></div>
                <Button asChild><Link href="/admin/series/create">+ إضافة عمل جديد</Link></Button>
            </header>
            <form onSubmit={onSearch} className="grid gap-3 rounded-xl border bg-card p-4 sm:grid-cols-2 lg:grid-cols-[minmax(180px,2fr)_repeat(3,minmax(130px,1fr))_auto_auto]">
                <div className="space-y-1"><label htmlFor="search" className="text-sm font-semibold">البحث بالعنوان</label>
                    <Input id="search" placeholder="اسم المانجا أو المانهوا" value={query.search} onChange={e => setQuery(previous => ({ ...previous, search: e.target.value }))} maxLength={100} /></div>
                <div className="space-y-1"><label htmlFor="format" className="text-sm font-semibold">النوع</label><select id="format" className={selectClass} value={query.format} onChange={e => setQuery(previous => ({ ...previous, format: e.target.value }))}><option value="">جميع الأنواع</option>{Object.entries(formats).map(([id, label]) => <option key={id} value={id}>{label}</option>)}</select></div>
                <div className="space-y-1"><label htmlFor="status" className="text-sm font-semibold">حالة النشر</label><select id="status" className={selectClass} value={query.status} onChange={e => setQuery(previous => ({ ...previous, status: e.target.value }))}><option value="">الكل</option>{Object.entries(states).map(([id, label]) => <option key={id} value={id}>{label}</option>)}</select></div>
                <div className="space-y-1"><label htmlFor="completion" className="text-sm font-semibold">حالة القصة</label><select id="completion" className={selectClass} value={query.completion} onChange={e => setQuery(previous => ({ ...previous, completion: e.target.value }))}><option value="">الكل</option>{Object.entries(completion).map(([id, label]) => <option key={id} value={id}>{label}</option>)}</select></div>
                <Button type="submit" className="self-end">تصفية</Button><Button type="button" variant="outline" className="self-end" onClick={reset}>مسح</Button>
            </form>
            <div className="space-y-3 md:hidden">
                {series.data.length === 0 ? <div className="rounded-2xl border bg-card p-8 text-center text-sm text-muted-foreground">لا توجد أعمال مطابقة. غيّر الفلاتر أو أضف أول عمل.</div>
                    : series.data.map(item => <article key={item.id} className="rounded-2xl border bg-card p-4 shadow-sm">
                        <div className="flex items-start gap-3">
                            {item.cover_url ? <img src={item.cover_url} alt={`غلاف ${item.primary_title}`} loading="lazy" className="h-24 w-17 shrink-0 rounded-lg border object-cover" />
                                : <div className="flex h-24 w-17 shrink-0 items-center justify-center rounded-lg border bg-muted px-1 text-center text-xs text-muted-foreground">بلا غلاف</div>}
                            <div className="min-w-0 flex-1 space-y-2">
                                <h2 className="break-words text-base font-bold leading-snug">{item.primary_title}</h2>
                                <p dir="ltr" className="truncate text-left text-xs text-muted-foreground">{item.slug}</p>
                                <div className="flex flex-wrap gap-1.5 text-xs">
                                    <span className="rounded-full bg-primary/10 px-2.5 py-1 text-primary">{states[item.publication_status] ?? item.publication_status}</span>
                                    <span className="rounded-full bg-muted px-2.5 py-1">{formats[item.format] ?? item.format}</span>
                                    <span className="rounded-full bg-muted px-2.5 py-1">{completion[item.completion_status] ?? item.completion_status}</span>
                                </div>
                            </div>
                        </div>
                        <div className="mt-4 flex items-center justify-between border-t pt-3 text-xs text-muted-foreground">
                            <span>فصول منشورة: {item.published_chapters}</span>
                            <span>{new Date(item.updated_at).toLocaleDateString('ar-EG')}</span>
                        </div>
                        <div className="mt-3 grid gap-2 sm:grid-cols-2">
                            <Button asChild size="sm"><Link href={`/admin/series/${item.id}/content`}>إدارة المحتوى</Link></Button>
                            {item.publication_status === 'draft' && <Button asChild size="sm" variant="outline"><Link href={`/admin/series/${item.id}/edit`}>تعديل المسودة</Link></Button>}
                        </div>
                    </article>)}
            </div>
            <div className="hidden overflow-x-auto rounded-xl border bg-card md:block">
                <table className="w-full min-w-[740px] text-right text-sm">
                    <thead className="bg-muted/60"><tr>
                        <th scope="col" className="px-4 py-3">العمل</th><th scope="col" className="px-4 py-3">النوع</th>
                        <th scope="col" className="px-4 py-3">حالة القصة</th><th scope="col" className="px-4 py-3">النشر</th>
                        <th scope="col" className="px-4 py-3">فصول منشورة</th><th scope="col" className="px-4 py-3">الإجراءات</th>
                    </tr></thead>
                    <tbody>{series.data.length === 0 ? <tr><td colSpan={6} className="p-10 text-center text-muted-foreground">لا توجد أعمال مطابقة للفلاتر. غيّر البحث أو أضف أول عمل.</td></tr>
                        : series.data.map(item => <tr key={item.id} className="border-t">
                            <td className="px-4 py-3"><div className="flex min-w-64 items-center gap-3">
                                {item.cover_url ? <img src={item.cover_url} alt={`غلاف ${item.primary_title}`} loading="lazy" className="h-16 w-12 rounded border object-cover" />
                                    : <div className="flex h-16 w-12 shrink-0 items-center justify-center rounded border bg-muted text-xs text-muted-foreground">بلا غلاف</div>}
                                <div className="min-w-0"><p className="font-semibold">{item.primary_title}</p><p dir="ltr" className="text-xs text-muted-foreground">{item.slug}</p></div>
                            </div></td>
                            <td className="px-4 py-3">{formats[item.format] ?? item.format}</td>
                            <td className="px-4 py-3">{completion[item.completion_status] ?? item.completion_status}</td>
                            <td className="px-4 py-3"><span className="rounded-md border px-2 py-1 text-xs font-semibold">{states[item.publication_status] ?? item.publication_status}</span></td>
                            <td className="px-4 py-3">{item.published_chapters}</td>
                            <td className="px-4 py-3"><div className="flex flex-col gap-2"><Link className="font-semibold underline underline-offset-4" href={`/admin/series/${item.id}/content`}>إدارة المحتوى</Link>
                                {item.publication_status === 'draft' && <Link className="underline underline-offset-4" href={`/admin/series/${item.id}/edit`}>تعديل البيانات والغلاف</Link>}
                            </div></td>
                        </tr>)}</tbody>
                </table>
            </div>
            <nav aria-label="صفحات الأعمال" className="flex flex-wrap items-center gap-4 text-sm">
                {series.prev_page_url ? <Link href={series.prev_page_url} className="underline">السابق</Link> : <span className="text-muted-foreground">السابق</span>}
                <span>صفحة {series.current_page} من {series.last_page} • الإجمالي {series.total}</span>
                {series.next_page_url ? <Link href={series.next_page_url} className="underline">التالي</Link> : <span className="text-muted-foreground">التالي</span>}
            </nav>
        </section>
    </>;
}
