import { Breadcrumbs } from '@/components/breadcrumbs';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { Link, usePage } from '@inertiajs/react';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const { auth } = usePage().props;
    return (
        <header className="flex h-12 shrink-0 items-center gap-2 border-b border-sidebar-border/50 px-3 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:h-16 md:px-4">
            <div className="flex items-center gap-2">
                <SidebarTrigger className="-ml-1" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
            {auth.is_admin && breadcrumbs.length === 0 && <nav aria-label="تنقل الإدارة السريع" className="ml-auto flex items-center gap-3 text-xs font-semibold md:hidden">
                <Link href="/dashboard">اللوحة</Link>
                <Link href="/admin/series">الأعمال</Link>
                <Link href="/admin/series/create">إضافة</Link>
            </nav>}
        </header>
    );
}
