"use client";

import { apiFetch } from "@/lib/api";

import { useEffect, useMemo, useRef, useState } from "react";
import type { KeyboardEvent as ReactKeyboardEvent } from "react";
import Link from "@/lib/router";
import { usePathname } from "@/lib/router";
import * as Dialog from "@radix-ui/react-dialog";
import { Activity, Boxes, ChevronDown, Command, Home, Menu, MessageSquareText, Package, Search, Settings2, ShoppingBag, Truck, Users, X, MessageCircleQuestion } from "lucide-react";
import { modules } from "@/lib/modules";
import { detectRecordCode, mapLookupItems } from "@/lib/record-lookup.js";

type LookupResult = { kind: "order" | "shipment"; id: number; label: string; sublabel: string; href: string };

const icons = { overview: Home, orders: ShoppingBag, shipments: Truck, products: Package, customers: Users, support: MessageSquareText, faqs: MessageCircleQuestion, leads: Users, "wholesale-orders": Boxes, content: Package, marketing: Activity, reviews: Activity, finance: Activity, settings: Settings2 };
const groups = [
  { label: "Tổng quan", ids: ["overview"] },
  { label: "Vận hành", ids: ["orders", "shipments", "wholesale-orders"] },
  { label: "Quan hệ khách hàng", ids: ["customers", "support", "faqs", "leads", "reviews"] },
  { label: "Danh mục & tiếp thị", ids: ["products", "content", "marketing"] },
  { label: "Quản lý", ids: ["finance", "settings"] },
];

function normalize(value: string) {
  return value.normalize("NFD").replace(/[\u0300-\u036f]/g, "").replace(/đ/g, "d").replace(/Đ/g, "D").toLowerCase().replace(/[^a-z0-9\s]/g, "");
}

function NavLink({ item, active, compact = false, onNavigate, id }: { item: typeof modules[number]; active: boolean; compact?: boolean; onNavigate?: () => void; id?: string }) {
  const Icon = icons[item.id];
  return <Link id={id} href={item.href} onClick={onNavigate} className={`nav-link${active ? " is-active" : ""}${compact ? " nav-link-compact" : ""}`} aria-current={active ? "page" : undefined}>
    <Icon size={18} strokeWidth={1.8} aria-hidden="true" /><span>{item.label}</span>
  </Link>;
}

export function Navigation() {
  const pathname = usePathname();
  const [collapsed, setCollapsed] = useState(false);
  const [moreOpen, setMoreOpen] = useState(false);
  const [launcherOpen, setLauncherOpen] = useState(false);
  const [query, setQuery] = useState("");
  const [lookup, setLookup] = useState<{ key: string; results: LookupResult[] } | null>(null);
  const [activeOption, setActiveOption] = useState<string | null>(null);
  const searchRef = useRef<HTMLInputElement>(null);
  const activeId = modules.find((item) => item.href === pathname || item.href !== "/" && pathname.startsWith(item.href + "/"))?.id ?? "overview";
  const matches = useMemo(() => { const needle = normalize(query.trim()); if (!needle) return modules; return modules.filter((item) => normalize(`${item.label} ${item.id}`).includes(needle)); }, [query]);
  const detected = useMemo(() => detectRecordCode(query), [query]);
  const lookupReady = Boolean(detected.kind) && lookup?.key === detected.value;
  const lookupLoading = Boolean(detected.kind) && !lookupReady;
  const lookupResults = useMemo(() => (lookupReady && lookup ? lookup.results : []), [lookupReady, lookup]);
  const options = useMemo(() => [
    ...lookupResults.map((result, index) => ({ key: `record:${result.href}`, href: result.href, domId: `launcher-record-${index}` })),
    ...matches.map((item) => ({ key: `module:${item.id}`, href: item.href, domId: `launcher-module-${item.id}` })),
  ], [lookupResults, matches]);
  const activeOptionKey = options.find((option) => option.key === activeOption)?.key ?? options[0]?.key;
  const activeOptionDomId = options.find((option) => option.key === activeOptionKey)?.domId;

  useEffect(() => {
    if (!detected.kind) return;
    const { kind, value } = detected;
    const controller = new AbortController();
    const timer = window.setTimeout(async () => {
      try {
        const resource = kind === "order" ? "orders" : "shipments";
        const response = await apiFetch(`/api/dashboard/${resource}?search=${encodeURIComponent(value)}&per_page=5`, { signal: controller.signal });
        const payload = (await response.json()) as { items?: Record<string, unknown>[]; error?: string };
        if (!response.ok) throw new Error(payload.error || "Không kiểm tra được mã");
        const items = Array.isArray(payload.items) ? payload.items : [];
        setLookup({ key: value, results: mapLookupItems(kind, value, items) });
      } catch (error) {
        if (!(error instanceof DOMException && error.name === "AbortError")) setLookup({ key: value, results: [] });
      }
    }, 250);
    return () => { controller.abort(); window.clearTimeout(timer); };
  }, [detected]);

  function openLookup(href: string) {
    if (!href) return;
    setLauncherOpen(false); setQuery(""); setActiveOption(null);
    window.location.assign(href);
  }

  function onLauncherKeyDown(event: ReactKeyboardEvent<HTMLInputElement>) {
    if (!options.length) return;
    if (event.key === "ArrowDown" || event.key === "ArrowUp") {
      event.preventDefault();
      const index = options.findIndex((option) => option.key === activeOptionKey);
      const next = event.key === "ArrowDown" ? (index + 1) % options.length : (index - 1 + options.length) % options.length;
      setActiveOption(options[next].key);
      return;
    }
    if (event.key === "Enter") { event.preventDefault(); openLookup(options.find((option) => option.key === activeOptionKey)?.href ?? ""); }
  }

  useEffect(() => {
    function onKeyDown(event: KeyboardEvent) {
      if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === "k") {
        event.preventDefault(); setLauncherOpen(true);
      }
      if (event.key === "Escape") setMoreOpen(false);
    }
    window.addEventListener("keydown", onKeyDown);
    return () => window.removeEventListener("keydown", onKeyDown);
  }, []);

  return <>
    <div className={`navigation-area${collapsed ? " is-collapsed" : ""}`}>
      <nav id="primary-navigation" className="nav-groups" aria-label="Điều hướng chính">
        {groups.map((group) => <section className="nav-group" key={group.label}>
          <h2 className="nav-label">{group.label}</h2>
          {group.ids.map((id) => { const item = modules.find((entry) => entry.id === id)!; return <NavLink key={id} item={item} active={activeId === item.id} />; })}
        </section>)}
      </nav>
    </div>
    <button className="nav-collapse" type="button" onClick={() => setCollapsed((value) => !value)} aria-label={collapsed ? "Mở rộng điều hướng" : "Thu gọn điều hướng"} aria-expanded={!collapsed} aria-controls="primary-navigation" title={collapsed ? "Mở rộng" : "Thu gọn"}><ChevronDown size={16} aria-hidden="true" /></button>
    <nav className="mobile-tabs" aria-label="Điều hướng nhanh">
      {(["overview", "orders", "shipments"] as const).map((id) => { const item = modules.find((entry) => entry.id === id)!; return <NavLink key={id} item={item} active={activeId === id} compact />; })}
      <button className={`nav-link nav-link-compact${moreOpen ? " is-active" : ""}`} type="button" onClick={() => setMoreOpen((value) => !value)} aria-expanded={moreOpen}><Menu size={18} aria-hidden="true" /><span>Thêm</span></button>
      {moreOpen && <div className="mobile-more-panel"><button className="icon-button mobile-more-close" type="button" onClick={() => setMoreOpen(false)} aria-label="Đóng menu"><X size={18} /></button>{groups.slice(1).map((group) => <section className="nav-group" key={group.label}><h2 className="nav-label">{group.label}</h2>{group.ids.map((id) => { const item = modules.find((entry) => entry.id === id)!; return <NavLink key={id} item={item} active={activeId === id} onNavigate={() => setMoreOpen(false)} />; })}</section>)}</div>}
    </nav>
    <button className="launcher-trigger" type="button" onClick={() => setLauncherOpen(true)}><Search size={16} aria-hidden="true" /><span>Tìm khu vực…</span><kbd><Command size={11} aria-label="Command" /> K</kbd></button>
    <Dialog.Root open={launcherOpen} onOpenChange={(open) => { setLauncherOpen(open); if (open) setTimeout(() => searchRef.current?.focus(), 20); else { setQuery(""); setLookup(null); setActiveOption(null); } }}>
      <Dialog.Portal><Dialog.Overlay className="dialog-overlay" /><Dialog.Content className="launcher-dialog" aria-describedby={undefined}>
        <Dialog.Title className="sr-only">Đi tới khu vực</Dialog.Title>
        <div className="launcher-search"><Search size={18} aria-hidden="true" /><input ref={searchRef} aria-label="Tìm khu vực hoặc mã đơn/vận đơn" placeholder="Tìm trang quản trị hoặc mã đơn/vận đơn…" value={query} onChange={(event) => setQuery(event.target.value)} onKeyDown={onLauncherKeyDown} /><kbd>ESC</kbd></div>
        <div className="launcher-results" role="listbox" aria-label="Khu vực quản trị" aria-activedescendant={activeOptionDomId}>
          {lookupResults.length > 0 && <div className="launcher-section">
            <h3 className="launcher-section-label">Đi tới bản ghi</h3>
            {lookupResults.map((result, index) => <button key={result.href} id={`launcher-record-${index}`} type="button" role="option" aria-selected={activeOptionKey === `record:${result.href}`} className={`nav-link launcher-record${activeOptionKey === `record:${result.href}` ? " is-active" : ""}`} onClick={() => openLookup(result.href)} onMouseEnter={() => setActiveOption(`record:${result.href}`)}>
              {result.kind === "order" ? <ShoppingBag size={18} strokeWidth={1.8} aria-hidden="true" /> : <Truck size={18} strokeWidth={1.8} aria-hidden="true" />}
              <span className="launcher-record-text"><strong>{result.label}</strong>{result.sublabel && <small>{result.sublabel}</small>}</span>
            </button>)}
          </div>}
          {lookupLoading && <p className="launcher-hint" aria-live="polite">Đang kiểm tra mã…</p>}
          {lookupReady && lookupResults.length === 0 && <p className="launcher-hint" aria-live="polite">Không tìm thấy đơn/vận đơn cho “{query.trim()}”.</p>}
          {matches.map((item) => <NavLink key={item.id} id={`launcher-module-${item.id}`} item={item} active={activeId === item.id || activeOptionKey === `module:${item.id}`} onNavigate={() => setLauncherOpen(false)} />)}
          {matches.length === 0 && !detected.kind && <p className="empty-state">Không tìm thấy khu vực phù hợp.</p>}
        </div>
      </Dialog.Content></Dialog.Portal>
    </Dialog.Root>
  </>;
}
