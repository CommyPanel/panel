import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Boxes,
    Globe,
    ChevronsLeft,
    ChevronsRight,
    KeyRound,
    LayoutDashboard,
    LogOut,
    Menu,
    Palette,
    ScrollText,
    Server,
    ShieldCheck,
    UserCog,
    Users,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { Brand } from '@/components/brand';
import { ThemeMenu } from '@/components/theme-menu';
import { Alert } from '@/components/ui/alert';
import { Dropdown, DropdownItem } from '@/components/ui/dropdown';
import { HostBanner } from '@/components/host-banner';
import { fortify } from '@/lib/fortify';
import { cn } from '@/lib/utils';

type NavItem = {
    href: string;
    label: string;
    icon: LucideIcon;
    /** Hidden unless the user holds this permission. */
    permission?: string;
};

const NAV: { heading: string; items: NavItem[] }[] = [
    {
        heading: 'Menu',
        items: [
            { href: '/dashboard', label: 'Dashboard', icon: LayoutDashboard },
            {
                href: '/apps',
                label: 'Apps',
                icon: Boxes,
                permission: 'apps.view',
            },
            {
                href: '/domains',
                label: 'Domains',
                icon: Globe,
                permission: 'domains.view',
            },
            {
                href: '/server',
                label: 'Server',
                icon: Server,
                permission: 'server.view',
            },
        ],
    },
    {
        heading: 'Access',
        items: [
            {
                href: '/users',
                label: 'Users',
                icon: Users,
                permission: 'users.view',
            },
            {
                href: '/roles',
                label: 'Roles',
                icon: UserCog,
                permission: 'roles.view',
            },
            {
                href: '/permissions',
                label: 'Permissions',
                icon: KeyRound,
                permission: 'permissions.view',
            },
        ],
    },
    {
        heading: 'Settings',
        items: [
            {
                href: '/settings/appearance',
                label: 'Appearance',
                icon: Palette,
            },
            {
                href: '/settings/security',
                label: 'Security',
                icon: ShieldCheck,
            },
            {
                href: '/settings/audit-log',
                label: 'Audit log',
                icon: ScrollText,
                permission: 'audit.view',
            },
        ],
    },
];

const COLLAPSE_KEY = 'commypanel-sidebar-collapsed';

function readCollapsed(): boolean {
    try {
        return localStorage.getItem(COLLAPSE_KEY) === '1';
    } catch {
        return false;
    }
}

type SidebarProps = {
    collapsed: boolean;
    url: string;
    name: string;
    onNavigate: () => void;
};

function Sidebar({ collapsed, url, name, onNavigate }: SidebarProps) {
    const { permissions } = usePage().props.auth;
    // Only what the user may open; a group with nothing left disappears.
    const visibleNav = NAV.map((group) => ({
        ...group,
        items: group.items.filter(
            (item) =>
                item.permission === undefined ||
                permissions.includes(item.permission),
        ),
    })).filter((group) => group.items.length > 0);

    return (
        <div className="flex h-full flex-col">
            <div
                className={cn(
                    'flex h-16 shrink-0 items-center px-5',
                    collapsed && 'lg:justify-center lg:px-0',
                )}
            >
                <Brand name={name} showName={!collapsed} />
            </div>
            <nav
                aria-label="Main navigation"
                className="flex-1 space-y-6 overflow-y-auto px-3 py-4"
            >
                {visibleNav.map((group) => (
                    <div key={group.heading}>
                        <p
                            className={cn(
                                'mb-2 px-3 text-xs font-medium tracking-wider text-base-content/60 uppercase',
                                collapsed && 'lg:sr-only',
                            )}
                        >
                            {group.heading}
                        </p>
                        <ul className="space-y-1">
                            {group.items.map((item) => {
                                const active = url.startsWith(item.href);

                                return (
                                    <li key={item.href}>
                                        <Link
                                            href={item.href}
                                            onClick={onNavigate}
                                            aria-current={
                                                active ? 'page' : undefined
                                            }
                                            title={
                                                collapsed
                                                    ? item.label
                                                    : undefined
                                            }
                                            className={cn(
                                                'flex items-center gap-3 rounded-field px-3 py-2 text-sm font-medium transition-colors',
                                                active
                                                    ? 'bg-primary/10 text-primary'
                                                    : 'text-base-content/80 hover:bg-base-200',
                                                collapsed &&
                                                    'lg:justify-center',
                                            )}
                                        >
                                            <item.icon
                                                className="size-5 shrink-0"
                                                aria-hidden
                                            />
                                            <span
                                                className={cn(
                                                    collapsed && 'lg:sr-only',
                                                )}
                                            >
                                                {item.label}
                                            </span>
                                        </Link>
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                ))}
            </nav>
        </div>
    );
}

type Props = { title: string; children: ReactNode };

export default function AppLayout({ title, children }: Props) {
    const { name, auth, flash } = usePage().props;
    const { url } = usePage();
    const [drawerOpen, setDrawerOpen] = useState(false);
    const [collapsed, setCollapsed] = useState(readCollapsed);

    useEffect(() => {
        if (!drawerOpen) return;

        const onKey = (event: KeyboardEvent) =>
            event.key === 'Escape' && setDrawerOpen(false);
        document.addEventListener('keydown', onKey);

        return () => document.removeEventListener('keydown', onKey);
    }, [drawerOpen]);

    const toggleCollapsed = () =>
        setCollapsed((value) => {
            try {
                localStorage.setItem(COLLAPSE_KEY, value ? '0' : '1');
            } catch {
                // Preference only lasts for this page view.
            }

            return !value;
        });

    const initial = auth.user.name.trim().charAt(0).toUpperCase() || '?';

    return (
        <>
            <Head title={title} />
            <div className="min-h-screen">
                {/* Desktop sidebar */}
                <aside
                    className={cn(
                        'fixed inset-y-0 left-0 z-30 hidden border-r border-base-300 bg-base-100 transition-[width] lg:block',
                        collapsed ? 'w-20' : 'w-64',
                    )}
                >
                    <Sidebar
                        collapsed={collapsed}
                        url={url}
                        name={name}
                        onNavigate={() => {}}
                    />
                </aside>

                {/* Mobile drawer */}
                {drawerOpen && (
                    <div className="fixed inset-0 z-40 lg:hidden">
                        <button
                            type="button"
                            aria-label="Close menu"
                            className="absolute inset-0 bg-black/50"
                            onClick={() => setDrawerOpen(false)}
                        />
                        <aside className="absolute inset-y-0 left-0 w-72 max-w-[85vw] bg-base-100 shadow-xl">
                            <button
                                type="button"
                                aria-label="Close menu"
                                className="btn absolute top-4 right-3 btn-circle btn-text btn-sm"
                                onClick={() => setDrawerOpen(false)}
                            >
                                <X className="size-5" />
                            </button>
                            <Sidebar
                                collapsed={false}
                                url={url}
                                name={name}
                                onNavigate={() => setDrawerOpen(false)}
                            />
                        </aside>
                    </div>
                )}

                <div
                    className={cn(
                        'min-w-0 transition-[padding]',
                        collapsed ? 'lg:pl-20' : 'lg:pl-64',
                    )}
                >
                    <header className="sticky top-0 z-20 flex h-16 items-center gap-2 border-b border-base-300 bg-base-100 px-4 sm:px-6">
                        <button
                            type="button"
                            aria-label="Open menu"
                            className="btn btn-circle btn-text btn-sm lg:hidden"
                            onClick={() => setDrawerOpen(true)}
                        >
                            <Menu className="size-5" />
                        </button>
                        <button
                            type="button"
                            aria-label={
                                collapsed
                                    ? 'Expand sidebar'
                                    : 'Collapse sidebar'
                            }
                            className="btn hidden btn-circle btn-text btn-sm lg:inline-flex"
                            onClick={toggleCollapsed}
                        >
                            {collapsed ? (
                                <ChevronsRight className="size-5" />
                            ) : (
                                <ChevronsLeft className="size-5" />
                            )}
                        </button>

                        <div className="ml-auto flex items-center gap-1">
                            <ThemeMenu />
                            <Dropdown
                                label="Account menu"
                                trigger={
                                    <>
                                        <span className="flex size-7 items-center justify-center rounded-full bg-primary/10 text-sm font-semibold text-primary">
                                            {initial}
                                        </span>
                                        <span className="hidden max-w-40 truncate text-sm sm:inline">
                                            {auth.user.name}
                                        </span>
                                    </>
                                }
                            >
                                {(close) => (
                                    <>
                                        <div className="mb-1 border-b border-base-300 px-3 py-2">
                                            <p className="truncate text-sm font-medium">
                                                {auth.user.name}
                                            </p>
                                            <p className="truncate text-xs text-base-content/70">
                                                {auth.user.email}
                                            </p>
                                        </div>
                                        <DropdownItem
                                            onSelect={() => {
                                                close();
                                                router.visit(
                                                    '/settings/security',
                                                );
                                            }}
                                        >
                                            <ShieldCheck className="size-4" />
                                            Security
                                        </DropdownItem>
                                        <DropdownItem
                                            tone="danger"
                                            onSelect={() => {
                                                close();
                                                router.post(fortify.logout);
                                            }}
                                        >
                                            <LogOut className="size-4" />
                                            Log out
                                        </DropdownItem>
                                    </>
                                )}
                            </Dropdown>
                        </div>
                    </header>

                    <main className="min-w-0 space-y-6 p-4 sm:p-6 lg:p-8">
                        <HostBanner />
                        {flash.status && (
                            <Alert variant="success">{flash.status}</Alert>
                        )}
                        {flash.warning && (
                            <Alert variant="warning">{flash.warning}</Alert>
                        )}
                        {children}
                    </main>
                </div>
            </div>
        </>
    );
}
