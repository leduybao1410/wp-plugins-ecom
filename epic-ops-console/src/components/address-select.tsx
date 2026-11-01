"use client";

import { apiFetch } from "@/lib/api";

import { useEffect, useId, useMemo, useRef, useState } from "react";

/**
 * Typable province/ward pickers for the direct-order form, ported from the
 * storefront's `VietnamNewAddressSelect` (checkout / free-sample) so staff get
 * the same accent-insensitive combobox the customer-facing checkout uses. The
 * options come from the admin adapter's cached ViettelPost master data
 * (`/api/dashboard/address/provinces|wards`, shape `{ id, name }`).
 *
 * A plain <select> is painful once a province has 100+ wards, and typing
 * "da lat" should match "Đà Lạt" either way. Only an exact match — typed or
 * picked — is committed via onChange, so a half-typed search can't silently
 * submit the wrong id.
 */

export type AddressOption = { id: string; name: string };

/** Accent-insensitive normalization, mirroring the storefront and the WP ward combo. */
export function normalizeVN(value: string): string {
  return value
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .replace(/đ/g, "d")
    .replace(/Đ/g, "D")
    .toLowerCase()
    .trim();
}

/** Ranks options: prefix matches first, then match position, length, alphabetical. Capped at 30. */
export function filterOptions(options: AddressOption[], query: string): AddressOption[] {
  const q = normalizeVN(query);
  if (!q) return [];
  const scored: { item: AddressOption; rank: number; index: number; length: number }[] = [];
  for (const item of options) {
    const normName = normalizeVN(item.name);
    const index = normName.indexOf(q);
    if (index === -1) continue;
    scored.push({ item, rank: index === 0 ? 0 : 1, index, length: normName.length });
  }
  scored.sort((a, b) => {
    if (a.rank !== b.rank) return a.rank - b.rank;
    if (a.index !== b.index) return a.index - b.index;
    if (a.length !== b.length) return a.length - b.length;
    return a.item.name.localeCompare(b.item.name);
  });
  return scored.slice(0, 30).map((entry) => entry.item);
}

function SearchableSelect({
  options,
  onChange,
  placeholder,
  initialName = "",
  disabled = false,
}: {
  options: AddressOption[];
  onChange: (id: string, name: string) => void;
  placeholder: string;
  initialName?: string;
  disabled?: boolean;
}) {
  const [query, setQuery] = useState(initialName);
  const [open, setOpen] = useState(false);
  const [activeIndex, setActiveIndex] = useState(-1);
  const blurTimeout = useRef<ReturnType<typeof setTimeout> | null>(null);
  const value = useRef(initialName);
  const listboxId = useId();

  useEffect(() => {
    return () => {
      if (blurTimeout.current) clearTimeout(blurTimeout.current);
    };
  }, []);

  const suggestions = useMemo(() => filterOptions(options, query), [options, query]);

  function selectOption(option: AddressOption) {
    value.current = option.name;
    onChange(option.id, option.name);
    setQuery(option.name);
    setOpen(false);
    setActiveIndex(-1);
  }

  function handleInputChange(text: string) {
    setQuery(text);
    setOpen(true);
    setActiveIndex(filterOptions(options, text).length > 0 ? 0 : -1);
    const exact = options.find((option) => normalizeVN(option.name) === normalizeVN(text));
    if (exact) {
      value.current = exact.name;
      onChange(exact.id, exact.name);
    } else if (value.current) {
      value.current = "";
      onChange("", "");
    }
  }

  function handleBlur() {
    blurTimeout.current = setTimeout(() => {
      const exact = options.find((option) => normalizeVN(option.name) === normalizeVN(query));
      if (!exact && value.current) {
        value.current = "";
        onChange("", "");
      }
      setOpen(false);
    }, 150);
  }

  return (
    <span style={{ position: "relative", display: "block" }}>
      <input
        type="text"
        autoComplete="off"
        role="combobox"
        aria-autocomplete="list"
        aria-expanded={open && suggestions.length > 0}
        aria-controls={listboxId}
        aria-activedescendant={open && activeIndex >= 0 && suggestions[activeIndex] ? `${listboxId}-${activeIndex}` : undefined}
        value={query}
        placeholder={placeholder}
        disabled={disabled}
        onChange={(event) => handleInputChange(event.target.value)}
        onFocus={() => {
          if (blurTimeout.current) clearTimeout(blurTimeout.current);
          if (query) setOpen(true);
        }}
        onBlur={handleBlur}
        onKeyDown={(event) => {
          if (event.key === "ArrowDown") {
            event.preventDefault();
            setOpen(true);
            setActiveIndex((index) => Math.min(index + 1, suggestions.length - 1));
          } else if (event.key === "ArrowUp") {
            event.preventDefault();
            setActiveIndex((index) => Math.max(index - 1, 0));
          } else if (event.key === "Enter") {
            if (open && activeIndex >= 0 && suggestions[activeIndex]) {
              event.preventDefault();
              selectOption(suggestions[activeIndex]);
            }
          } else if (event.key === "Escape") {
            setOpen(false);
          }
        }}
        className="search-input"
      />
      {open && query.trim() !== "" && suggestions.length === 0 && (
        <ul
          role="listbox"
          aria-label="Không có kết quả"
          style={{
            position: "absolute",
            insetInline: 0,
            top: "100%",
            zIndex: 40,
            margin: "4px 0 0",
            padding: "9px 11px",
            listStyle: "none",
            borderRadius: 10,
            border: "1px solid var(--line-strong)",
            background: "var(--surface)",
            boxShadow: "var(--shadow-sm)",
            color: "var(--muted)",
            fontSize: 13,
          }}
        >
          <li>Không tìm thấy kết quả</li>
        </ul>
      )}
      {open && suggestions.length > 0 && (
        <ul
          id={listboxId}
          role="listbox"
          style={{
            position: "absolute",
            insetInline: 0,
            top: "100%",
            zIndex: 40,
            margin: "4px 0 0",
            maxHeight: 260,
            overflowY: "auto",
            listStyle: "none",
            padding: 4,
            borderRadius: 10,
            border: "1px solid var(--line-strong)",
            background: "var(--surface)",
            boxShadow: "var(--shadow-sm)",
          }}
        >
          {suggestions.map((option, index) => (
            <li
              key={option.id}
              id={`${listboxId}-${index}`}
              role="option"
              aria-selected={index === activeIndex}
              onMouseDown={(event) => {
                event.preventDefault();
                selectOption(option);
              }}
              onMouseEnter={() => setActiveIndex(index)}
              style={{
                cursor: "pointer",
                padding: "9px 11px",
                borderRadius: 7,
                fontSize: 13,
                whiteSpace: "nowrap",
                overflow: "hidden",
                textOverflow: "ellipsis",
                background: index === activeIndex ? "var(--accent)" : "transparent",
                color: index === activeIndex ? "var(--surface)" : "var(--ink)",
              }}
            >
              {option.name}
            </li>
          ))}
        </ul>
      )}
    </span>
  );
}

/** Province/city picker fed by /api/dashboard/address/provinces. */
export function ProvinceSelect({
  onChange,
  placeholder,
  initialName = "",
}: {
  onChange: (id: string, name: string) => void;
  placeholder: string;
  initialName?: string;
}) {
  const [options, setOptions] = useState<AddressOption[]>([]);

  useEffect(() => {
    let active = true;
    void (async () => {
      try {
        const response = await apiFetch("/api/dashboard/address/provinces");
        const payload = await response.json();
        if (active && response.ok) setOptions((payload.items ?? []) as AddressOption[]);
      } catch {
        /* Province data is optional; staff can retry by reopening the form. */
      }
    })();
    return () => { active = false; };
  }, []);

  return <SearchableSelect options={options} onChange={onChange} placeholder={placeholder} initialName={initialName} />;
}

/** Ward/commune picker fed by /api/dashboard/address/wards for the chosen province. */
export function WardSelect({
  provinceId,
  onChange,
  placeholder,
  initialName = "",
}: {
  provinceId: string;
  onChange: (id: string, name: string) => void;
  placeholder: string;
  initialName?: string;
}) {
  const [options, setOptions] = useState<AddressOption[]>([]);

  useEffect(() => {
    let active = true;
    void (async () => {
      if (!provinceId) { if (active) setOptions([]); return; }
      try {
        const response = await apiFetch(`/api/dashboard/address/wards?province_id=${encodeURIComponent(provinceId)}`);
        const payload = await response.json();
        if (active && response.ok) setOptions((payload.items ?? []) as AddressOption[]);
      } catch {
        /* Ward list is optional. */
      }
    })();
    return () => { active = false; };
  }, [provinceId]);

  return <SearchableSelect options={options} onChange={onChange} placeholder={placeholder} initialName={initialName} disabled={!provinceId} />;
}
