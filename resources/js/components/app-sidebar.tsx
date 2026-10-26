import { Link, usePage } from '@inertiajs/react';
import { LayoutGrid, LibraryBig, Plus, UserRound } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const adminNavItems: NavItem[] = [
    { title: 'لوحة التحكم', href: dashboard(), icon: LayoutGrid },
    { title: 'إدارة الأعمال', href: '/admin/series', icon: LibraryBig },
    { title: 'إضافة عمل جديد', href: '/admin/series/create', icon: Plus },
];
const readerNavItems: NavItem[] = [
    { title: 'الملف الشخصي', href: '/settings/profile', icon: UserRound },
];

export function AppSidebar() {
    const { auth } = usePage().props;
    const mainNavItems = auth.is_admin ? adminNavItems : readerNavItems;
    const homeHref = auth.is_admin ? dashboard() : '/settings/profile';
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={homeHref} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
