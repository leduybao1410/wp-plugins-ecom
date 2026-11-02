import { Search } from "lucide-react";
import { Navigation } from "@/components/navigation";
import { ThemeControl } from "@/components/theme-control";
import { LogoutButton } from "@/components/logout-button";
import { Overview } from "@/components/overview";
import { RecordsPage } from "@/components/records-page";
import { RecordsHub, SettingsPage } from "@/components/hub-pages";
import { SupportConversations } from "@/components/support-conversations";
import { FaqManager } from "@/components/faq-manager";
import { modules } from "@/lib/modules";
import { Link, usePathname } from "@/lib/router";

function resolveRoute(pathname: string) {
  if (pathname === "/" || pathname === "") return <Overview />;
  if (pathname === "/support/conversations" || pathname.startsWith("/support/conversations/")) return <SupportConversations />;
  if (pathname === "/faqs") return <FaqManager />;
  const definition = modules.find((item) => item.href === pathname);
  if (!definition) return <NotFound pathname={pathname} />;
  if (definition.id === "finance" || definition.id === "marketing") return <RecordsHub module={definition.id} title={definition.label} />;
  if (definition.id === "wholesale-orders") return <RecordsHub module="wholesale" title={definition.label} />;
  if (definition.id === "settings") return <SettingsPage />;
  return <RecordsPage module={definition.id} title={definition.label} resource={definition.resource as string} />;
}

function NotFound({ pathname }: { pathname: string }) {
  return (
    <section>
      <div className="page-heading">
        <div>
          <div className="eyebrow">Không tìm thấy</div>
          <h1>Trang không tồn tại</h1>
          <p>
            Đường dẫn <code>{pathname}</code> không thuộc bảng vận hành EPIC.
          </p>
        </div>
        <Link className="action-button" href="/">
          Về tổng quan
        </Link>
      </div>
    </section>
  );
}

export function App() {
  const pathname = usePathname();
  const user = window.EPIC_OPS?.user;
  const initial = (user?.name ?? "E").slice(0, 1).toUpperCase();
  return (
    <div className="admin-shell">
      <aside className="sidebar">
        <Link href="/" className="brand" style={{ textDecoration: "none", color: "inherit" }}>
          <span className="brand-mark">E</span>
          <div>
            <strong>EPIC</strong>
            <small>TRUNG TÂM VẬN HÀNH</small>
          </div>
        </Link>
        <Navigation />
        <div className="side-note">
          <span className="connection-dot" />
          Dữ liệu được đồng bộ với WordPress
        </div>
      </aside>
      <div className="main-area">
        <header className="topbar">
          <div className="topbar-context">
            <span className="crumb">
              EPIC Coffee Roaster <span aria-hidden="true">/</span> <strong>Quản trị</strong>
            </span>
            <span className="topbar-search-hint">
              <Search size={14} /> Tìm nhanh <kbd>⌘ K</kbd>
            </span>
          </div>
          <div className="user-area">
            <ThemeControl />
            <span className="avatar" aria-hidden="true">
              {initial}
            </span>
            <div className="user-copy">
              <strong>{user?.name ?? "Quản trị"}</strong>
              <small>Quản trị viên</small>
            </div>
            <LogoutButton />
          </div>
        </header>
        <main className="content">{resolveRoute(pathname)}</main>
      </div>
    </div>
  );
}
