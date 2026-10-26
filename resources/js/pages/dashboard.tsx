import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, BookOpenCheck, BookText, CircleAlert, Clock3, FolderPen, LibraryBig, Plus, RefreshCw, Sparkles } from 'lucide-react';
import { useEffect, useState } from 'react';
import { dashboard } from '@/routes';

type Summary = {
    stats: { series: number; drafts: number; published: number; published_chapters: number };
    recent: { id: string; title: string; format: string; status: string; updated_at: string | null; cover_url: string | null }[];
};
const statusLabels: Record<string, string> = { draft: 'مسودة', reviewed: 'قيد المراجعة', published: 'منشور', archived: 'مؤرشف', revoked: 'مسحوب' };
const formatLabels: Record<string, string> = { manga: 'مانجا', manhwa: 'مانهوا', manhua: 'مانهوا صينية', webtoon: 'ويب تون' };
const number = new Intl.NumberFormat('ar-EG');
const date = new Intl.DateTimeFormat('ar-EG', { day: 'numeric', month: 'short', year: 'numeric' });

function StatCard({ label, value, icon: Icon, accent }: { label: string; value: number | null | undefined; icon: typeof LibraryBig; accent: string }) {
    return <div className="rounded-2xl border border-[#e8eaf2] bg-white p-5 shadow-[0_12px_36px_-28px_rgba(29,35,86,.35)] dark:border-white/10 dark:bg-[#191d35]">
        <div className="flex items-start justify-between gap-3">
            <span className={`flex size-11 items-center justify-center rounded-xl ${accent}`}><Icon className="size-5" /></span>
            <span className="text-3xl font-bold text-[#202541] dark:text-white" aria-live="polite">{value === undefined ? <span className="inline-block h-8 w-12 animate-pulse rounded bg-slate-100 dark:bg-white/10" /> : value === null ? '—' : number.format(value)}</span>
        </div>
        <p className="mt-6 text-sm font-medium text-[#737a91] dark:text-[#aab0ca]">{label}</p>
    </div>;
}

export default function Dashboard() {
    const [summary, setSummary] = useState<Summary | null>(null);
    const [error, setError] = useState(false);
    const [attempt, setAttempt] = useState(0);
    useEffect(() => {
        const controller = new AbortController();
        setSummary(null);
        setError(false);
        fetch('/admin/dashboard/summary', { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: controller.signal })
            .then(async response => {
                if (!response.ok) throw new Error('Summary unavailable');
                return response.json() as Promise<Summary>;
            })
            .then(setSummary)
            .catch(() => { if (!controller.signal.aborted) setError(true); });
        return () => controller.abort();
    }, [attempt]);

    return <>
        <Head title="لوحة التحكم" />
        <main dir="rtl" className="min-h-full flex-1 bg-[#f7f8fc] text-[#202541] dark:bg-[#101426] dark:text-white">
            <div className="mx-auto max-w-[1500px] space-y-7 px-4 py-7 sm:px-7 lg:px-10 lg:py-10">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div className="mb-3 inline-flex items-center gap-2 rounded-full border border-[#dee2f8] bg-white px-3 py-1.5 text-xs font-semibold text-[#5d60a5] dark:border-white/10 dark:bg-white/5 dark:text-[#b9b9ff]"><Sparkles className="size-3.5" /> مركز إدارة Manga Gate</div>
                        <h1 className="text-3xl font-bold tracking-tight sm:text-4xl">لوحة التحكم</h1>
                        <p className="mt-2 text-sm text-[#737a91] dark:text-[#aab0ca]">نظرة سريعة على مكتبة الأعمال، وحالة النشر، وآخر التحديثات.</p>
                    </div>
                    <Link href="/admin/series/create" className="inline-flex h-11 items-center gap-2 rounded-xl bg-[#655de8] px-5 text-sm font-semibold text-white shadow-[0_12px_24px_-12px_rgba(101,93,232,.8)] transition hover:bg-[#5148d4]"><Plus className="size-4" /> إضافة عمل جديد</Link>
                </header>

                <section className="relative overflow-hidden rounded-[26px] bg-[#222447] p-6 text-white shadow-[0_24px_44px_-30px_rgba(22,25,68,.7)] sm:p-8 lg:p-10">
                    <div className="pointer-events-none absolute -left-16 -top-28 size-72 rounded-full bg-[#655de8]/40 blur-3xl" />
                    <div className="relative flex flex-wrap items-end justify-between gap-6">
                        <div className="max-w-xl">
                            <span className="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3 py-1.5 text-xs font-medium text-[#dbdcff]"><LibraryBig className="size-3.5" /> مساحة إدارة المحتوى</span>
                            <h2 className="mt-5 text-2xl font-bold leading-snug sm:text-3xl">قصص رائعة تبدأ بإدارة واضحة.</h2>
                            <p className="mt-3 text-sm leading-7 text-[#c9cbe6]">أضف عملاً، راجع بياناته وغلافه، ثم تابع فصوله وحالة نشره من مكان واحد.</p>
                        </div>
                        <Link href="/admin/series" className="inline-flex items-center gap-2 rounded-xl border border-white/25 bg-white/10 px-4 py-3 text-sm font-semibold transition hover:bg-white/20">عرض كل الأعمال <ArrowLeft className="size-4" /></Link>
                    </div>
                </section>

                {error && <div role="alert" className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
                    <span className="flex items-center gap-2"><CircleAlert className="size-4" /> تعذر تحميل بيانات اللوحة. تحقق من الاتصال أو سجّل الدخول مجددًا.</span>
                    <button type="button" onClick={() => setAttempt(previous => previous + 1)} className="inline-flex items-center gap-1 font-bold underline underline-offset-4"><RefreshCw className="size-3.5" /> إعادة المحاولة</button>
                </div>}

                <section aria-label="إحصائيات المحتوى" className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard label="إجمالي الأعمال" value={error ? null : summary?.stats.series} icon={LibraryBig} accent="bg-[#edeaff] text-[#655de8]" />
                    <StatCard label="المسودات" value={error ? null : summary?.stats.drafts} icon={FolderPen} accent="bg-[#fff2e5] text-[#d88c35]" />
                    <StatCard label="الأعمال المنشورة" value={error ? null : summary?.stats.published} icon={BookOpenCheck} accent="bg-[#e8f8ef] text-[#238c62]" />
                    <StatCard label="الفصول المنشورة" value={error ? null : summary?.stats.published_chapters} icon={BookText} accent="bg-[#e8f2ff] text-[#447ec3]" />
                </section>

                <div className="grid gap-5 xl:grid-cols-[minmax(0,1.65fr)_minmax(280px,1fr)]">
                    <section className="overflow-hidden rounded-2xl border border-[#e8eaf2] bg-white shadow-[0_12px_36px_-28px_rgba(29,35,86,.35)] dark:border-white/10 dark:bg-[#191d35]">
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-[#edf0f6] px-5 py-5 dark:border-white/10 sm:px-6">
                            <div><h2 className="text-lg font-bold">آخر الأعمال تحديثًا</h2><p className="mt-1 text-xs text-[#858ba0] dark:text-[#aab0ca]">تابع ما تغيّر مؤخرًا داخل المكتبة</p></div>
                            <Link href="/admin/series" className="inline-flex items-center gap-1 text-sm font-semibold text-[#655de8] dark:text-[#aaa5ff]">كل الأعمال <ArrowLeft className="size-4" /></Link>
                        </div>
                        {summary === null && !error ? <div className="space-y-3 p-5 sm:p-6" aria-label="جاري تحميل الأعمال">{[1, 2, 3].map(item => <div key={item} className="h-16 animate-pulse rounded-xl bg-slate-100 dark:bg-white/10" />)}</div>
                            : summary?.recent.length ? <div className="divide-y divide-[#f0f1f6] dark:divide-white/10">{summary.recent.map(item => <Link key={item.id} href={`/admin/series/${item.id}/content`} className="flex items-center gap-4 px-5 py-3.5 transition hover:bg-[#f8f8fe] dark:hover:bg-white/5 sm:px-6">
                                {item.cover_url ? <img src={item.cover_url} alt="" loading="lazy" className="h-14 w-11 shrink-0 rounded-lg object-cover" /> : <span className="flex h-14 w-11 shrink-0 items-center justify-center rounded-lg bg-[#eff0fa] text-[#888db2] dark:bg-white/10"><BookText className="size-5" /></span>}
                                <span className="min-w-0 flex-1"><span className="block truncate text-sm font-semibold">{item.title}</span><span className="mt-1 block text-xs text-[#8b91a5] dark:text-[#aab0ca]">{formatLabels[item.format] ?? item.format}{item.updated_at ? ` · ${date.format(new Date(item.updated_at))}` : ''}</span></span>
                                <span className={`shrink-0 rounded-full px-2.5 py-1 text-[11px] font-bold ${item.status === 'published' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300' : 'bg-[#f0efff] text-[#6860c8] dark:bg-white/10 dark:text-[#bcb8ff]'}`}>{statusLabels[item.status] ?? item.status}</span>
                                <ArrowLeft className="hidden size-4 shrink-0 text-[#a2a7bb] sm:block" />
                            </Link>)}</div>
                                : error ? <p className="p-8 text-center text-sm text-[#858ba0] dark:text-[#aab0ca]">تعذر عرض الأعمال حاليًا.</p>
                                : <div className="flex min-h-56 flex-col items-center justify-center p-8 text-center"><LibraryBig className="size-8 text-[#aaa5ed]" /><h3 className="mt-3 text-sm font-semibold">لا توجد أعمال بعد</h3><p className="mt-1 text-xs text-[#858ba0] dark:text-[#aab0ca]">أضف أول عمل لتظهر تحديثاته هنا.</p><Link href="/admin/series/create" className="mt-4 text-sm font-semibold text-[#655de8] dark:text-[#aaa5ff]">إضافة عمل جديد</Link></div>}
                    </section>

                    <section className="rounded-2xl border border-[#e8eaf2] bg-white p-5 shadow-[0_12px_36px_-28px_rgba(29,35,86,.35)] dark:border-white/10 dark:bg-[#191d35] sm:p-6">
                        <div className="flex items-center gap-3"><span className="flex size-10 items-center justify-center rounded-xl bg-[#edeaff] text-[#655de8]"><Clock3 className="size-5" /></span><div><h2 className="text-lg font-bold">مسار العمل</h2><p className="text-xs text-[#858ba0] dark:text-[#aab0ca]">خطوات إدارة المحتوى</p></div></div>
                        <ol className="mt-7 space-y-6 border-r-2 border-[#e8e6fb] pr-6 dark:border-white/10">
                            <li className="relative"><span className="absolute -right-[34px] top-0 flex size-5 items-center justify-center rounded-full bg-[#655de8] text-[10px] font-bold text-white">١</span><h3 className="text-sm font-bold">إنشاء عمل</h3><p className="mt-1 text-xs leading-6 text-[#858ba0] dark:text-[#aab0ca]">أدخل العنوان والتصنيف والوصف وبيانات العمل.</p></li>
                            <li className="relative"><span className="absolute -right-[34px] top-0 flex size-5 items-center justify-center rounded-full bg-[#dcd9fb] text-[10px] font-bold text-[#655de8]">٢</span><h3 className="text-sm font-bold">إضافة الغلاف والفصول</h3><p className="mt-1 text-xs leading-6 text-[#858ba0] dark:text-[#aab0ca]">جهّز صور العمل ومحتوى الفصول للمراجعة.</p></li>
                            <li className="relative"><span className="absolute -right-[34px] top-0 flex size-5 items-center justify-center rounded-full bg-[#dcd9fb] text-[10px] font-bold text-[#655de8]">٣</span><h3 className="text-sm font-bold">المراجعة والنشر</h3><p className="mt-1 text-xs leading-6 text-[#858ba0] dark:text-[#aab0ca]">تحقق من الحقوق والجاهزية ثم انشر المحتوى.</p></li>
                        </ol>
                        <Link href="/admin/series" className="mt-8 inline-flex w-full items-center justify-center gap-2 rounded-xl border border-[#dedff2] px-4 py-3 text-sm font-semibold text-[#5255a5] transition hover:bg-[#f5f5ff] dark:border-white/15 dark:text-[#bbb7ff] dark:hover:bg-white/5">فتح إدارة الأعمال <ArrowLeft className="size-4" /></Link>
                    </section>
                </div>
            </div>
        </main>
    </>;
}

Dashboard.layout = { breadcrumbs: [{ title: 'لوحة التحكم', href: dashboard() }] };
