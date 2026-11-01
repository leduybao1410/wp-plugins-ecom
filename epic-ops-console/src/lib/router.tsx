import { useMemo, useSyncExternalStore, type AnchorHTMLAttributes, type MouseEvent, type ReactNode } from "react";

const BASE = (window.EPIC_OPS?.base ?? "").replace(/\/$/, "");

const listeners = new Set<() => void>();

function emit() {
  for (const listener of listeners) listener();
}

function subscribe(listener: () => void) {
  listeners.add(listener);
  return () => listeners.delete(listener);
}

if (typeof window !== "undefined") {
  window.addEventListener("popstate", emit);
}

function withBase(to: string) {
  if (!BASE) return to;
  if (to === "/") return BASE;
  if (to === BASE || to.startsWith(`${BASE}/`) || to.startsWith(`${BASE}?`)) return to;
  return `${BASE}${to.startsWith("/") ? "" : "/"}${to}`;
}

/** Pathname without the /ops mount prefix, so components keep their Next.js hrefs. */
function normalize(pathname: string) {
  const raw = pathname || "/";
  if (!BASE) return trimSlash(raw);
  if (raw === BASE) return "/";
  if (raw.startsWith(`${BASE}/`)) return trimSlash(raw.slice(BASE.length)) || "/";
  return trimSlash(raw) || "/";
}

function trimSlash(pathname: string) {
  return pathname.length > 1 && pathname.endsWith("/") ? pathname.replace(/\/+$/, "") : pathname;
}

export function navigate(to: string, options: { replace?: boolean; scroll?: boolean } = {}) {
  const target = withBase(to);
  if (options.replace) window.history.replaceState(null, "", target);
  else window.history.pushState(null, "", target);
  if (options.scroll !== false) window.scrollTo(0, 0);
  emit();
}

export function usePathname() {
  return useSyncExternalStore(subscribe, () => normalize(window.location.pathname), () => "/");
}

export function useSearchParams() {
  const search = useSyncExternalStore(subscribe, () => window.location.search, () => "");
  return useMemo(() => new URLSearchParams(search), [search]);
}

export function useRouter() {
  return {
    push: (href: string) => navigate(href),
    replace: (href: string, options?: { scroll?: boolean }) => navigate(href, { replace: true, scroll: options?.scroll }),
    refresh: () => emit(),
    back: () => window.history.back(),
    forward: () => window.history.forward(),
    prefetch: () => undefined,
  };
}

type LinkProps = Omit<AnchorHTMLAttributes<HTMLAnchorElement>, "href"> & { href: string; children?: ReactNode; replace?: boolean; scroll?: boolean };

export function Link({ href, children, onClick, replace, scroll, ...rest }: LinkProps) {
  function handleClick(event: MouseEvent<HTMLAnchorElement>) {
    onClick?.(event);
    if (event.defaultPrevented) return;
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    if (rest.target && rest.target !== "_self") return;
    event.preventDefault();
    navigate(href, { replace, scroll });
  }
  return (
    <a href={withBase(href)} onClick={handleClick} {...rest}>
      {children}
    </a>
  );
}

export default Link;
