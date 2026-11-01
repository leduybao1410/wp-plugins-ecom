"use client";

import { useEffect, useRef, useState } from "react";
import { Monitor, Moon, Sun } from "lucide-react";
import { getThemePreference, resolveTheme, setThemePreference } from "@/lib/ui-preferences.js";

export function ThemeControl() {
  const selectRef = useRef<HTMLSelectElement>(null);
  const transitionTimer = useRef<number | undefined>(undefined);
  const [preference, setPreference] = useState<"system" | "light" | "dark">("system");
  const [resolvedTheme, setResolvedTheme] = useState<"light" | "dark">("light");

  function applyTheme(value: "system" | "light" | "dark", animate: boolean) {
    const nextTheme = resolveTheme(value, window.matchMedia("(prefers-color-scheme: dark)").matches);
    const root = document.documentElement;
    const themeChanged = root.dataset.theme !== nextTheme;
    if (animate && themeChanged) {
      root.classList.add("theme-transition");
      window.clearTimeout(transitionTimer.current);
      transitionTimer.current = window.setTimeout(() => root.classList.remove("theme-transition"), 240);
    }
    root.dataset.theme = nextTheme;
    setResolvedTheme(nextTheme);
  }

  useEffect(() => {
    const value = getThemePreference(window.localStorage);
    const media = window.matchMedia("(prefers-color-scheme: dark)");
    let cancelled = false;
    queueMicrotask(() => {
      if (cancelled) return;
      setPreference(value);
      if (selectRef.current) selectRef.current.value = value;
      setResolvedTheme(resolveTheme(value, media.matches));
    });
    const updateSystemTheme = () => { if (getThemePreference(window.localStorage) === "system") applyTheme("system", true); };
    media.addEventListener("change", updateSystemTheme);
    return () => {
      cancelled = true;
      media.removeEventListener("change", updateSystemTheme);
      window.clearTimeout(transitionTimer.current);
      document.documentElement.classList.remove("theme-transition");
    };
  }, []);

  function change(value: "system" | "light" | "dark") {
    setThemePreference(window.localStorage, value);
    setPreference(value);
    applyTheme(value, true);
  }

  const Icon = preference === "system" ? Monitor : resolvedTheme === "dark" ? Moon : Sun;
  const preferenceLabel = preference === "system" ? "Theo thiết bị" : preference === "dark" ? "Tối" : "Sáng";

  return <label className="theme-control" title={`Giao diện: ${preferenceLabel}`}>
    <span className="sr-only">Giao diện</span>
    <span key={`${preference}-${resolvedTheme}`} className="theme-icon" aria-hidden="true"><Icon size={18} strokeWidth={1.8} /></span>
    <select ref={selectRef} aria-label={`Giao diện: ${preferenceLabel}`} value={preference} onChange={(event) => change(event.target.value as "system" | "light" | "dark")}>
      <option value="system">Theo thiết bị</option><option value="light">Sáng</option><option value="dark">Tối</option>
    </select>
  </label>;
}
