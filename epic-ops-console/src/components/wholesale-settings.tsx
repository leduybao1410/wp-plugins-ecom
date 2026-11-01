"use client";

import { apiFetch } from "@/lib/api";

import { useEffect, useState } from "react";

type WholesaleCustomer = { id: number; name: string; email: string; level: string; vip: boolean };
type Level = { key: string; name: string; discount: number };
type Settings = { customers: WholesaleCustomer[]; levels: Record<string, Level>; default_level: string; revision: string; available_customers?: Array<{ id: number; name: string; email: string; selected: boolean }> };

export function WholesaleSettingsPage() {
  const [settings, setSettings] = useState<Settings | null>(null);
  const [customerIds, setCustomerIds] = useState<number[]>([]);
  const [customerLevels, setCustomerLevels] = useState<Record<string, string>>({});
  const [vipIds, setVipIds] = useState<number[]>([]);
  const [levelsText, setLevelsText] = useState("{}");
  const [defaultLevel, setDefaultLevel] = useState("");
  const [search, setSearch] = useState("");
  const [candidates, setCandidates] = useState<Settings["available_customers"]>([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  function apply(data: Settings) {
    setSettings(data);
    setCustomerIds(data.customers.map((customer) => customer.id));
    setCustomerLevels(Object.fromEntries(data.customers.map((customer) => [String(customer.id), customer.level])));
    setVipIds(data.customers.filter((customer) => customer.vip).map((customer) => customer.id));
    setLevelsText(JSON.stringify(Object.fromEntries(Object.entries(data.levels).map(([key, level]) => [key, { name: level.name, discount: level.discount }])), null, 2));
    setDefaultLevel(data.default_level);
  }

  useEffect(() => {
    apiFetch("/api/dashboard/wholesale/settings").then(async (response) => { const data = await response.json(); if (!response.ok) throw new Error(data.error || "Không tải được cấu hình khách sỉ"); apply(data); })
      .catch((e: unknown) => setError(e instanceof Error ? e.message : "Không tải được cấu hình khách sỉ"));
  }, []);

  useEffect(() => {
    if (search.trim().length < 2) return;
    const controller = new AbortController();
    const timer = window.setTimeout(() => {
      apiFetch(`/api/dashboard/wholesale/settings?search=${encodeURIComponent(search.trim())}`, { signal: controller.signal }).then(async (response) => {
        const data = await response.json(); if (!response.ok) throw new Error(data.error || "Không tìm được tài khoản"); setCandidates(data.available_customers ?? []);
      }).catch((e: unknown) => { if (e instanceof Error && e.name !== "AbortError") setError(e.message); });
    }, 250);
    return () => { window.clearTimeout(timer); controller.abort(); };
  }, [search]);

  function toggleCustomer(id: number) { setCustomerIds((current) => current.includes(id) ? current.filter((value) => value !== id) : [...current, id]); }
  function toggleVip(id: number) { setVipIds((current) => current.includes(id) ? current.filter((value) => value !== id) : [...current, id]); }

  async function save() {
    if (!settings) return;
    setBusy(true); setError(""); setNotice("");
    try {
      const levels = JSON.parse(levelsText) as Record<string, { name: string; discount: number }>;
      const response = await apiFetch("/api/dashboard/wholesale/settings", { method: "PATCH", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ expected_revision: settings.revision, customer_ids: customerIds, customer_levels: customerLevels, vip_customer_ids: vipIds, levels, default_level: defaultLevel }) });
      const payload = await response.json(); if (!response.ok) throw new Error(payload.error || "Không lưu được cấu hình bán sỉ");
      apply(payload); setNotice("Đã lưu danh sách khách sỉ và các mức giá.");
    } catch (e) { setError(e instanceof Error ? e.message : "Không lưu được cấu hình bán sỉ"); }
    finally { setBusy(false); }
  }

  return <>
    <div className="page-heading"><div><div className="eyebrow">EPIC / Bán sỉ</div><h1>Khách sỉ và mức giá</h1><p>Quản lý quyền mua sỉ, phân hạng chiết khấu và khách hàng VIP bằng dữ liệu hiện có của WooCommerce.</p></div><button className="action-button" type="button" disabled={busy || !settings} onClick={() => void save()}>Lưu cấu hình</button></div>
    {notice && <div className="success-box" role="status">{notice}</div>}{error && <div className="error-box" role="alert">{error}</div>}
    <div className="summary-grid">
      <section className="card section-card"><h2>Danh sách khách sỉ</h2>
        <label htmlFor="wholesale-search">Tìm tài khoản khách hàng</label><input id="wholesale-search" className="search-input" value={search} onChange={(event) => { setSearch(event.target.value); if (event.target.value.trim().length < 2) setCandidates([]); }} placeholder="Nhập tên, email hoặc tên đăng nhập…" />
        {search.trim().length >= 2 && <div style={{ marginTop: 10 }}>{candidates?.map((candidate) => <label key={candidate.id} className="alert-row" style={{ alignItems: "center" }}><span><strong>{candidate.name || `Tài khoản #${candidate.id}`}</strong><small style={{ display: "block", color: "var(--muted)" }}>{candidate.email}</small></span><input type="checkbox" checked={customerIds.includes(candidate.id)} onChange={() => toggleCustomer(candidate.id)} aria-label={`Cho phép bán sỉ cho ${candidate.name || candidate.email}`} /></label>)}{!candidates?.length && <p className="muted">Không tìm thấy tài khoản phù hợp.</p>}</div>}
        <div style={{ marginTop: 18 }}><strong>Đang được cấp quyền ({customerIds.length})</strong>{settings?.customers.filter((customer) => customerIds.includes(customer.id)).map((customer) => <div key={customer.id} className="alert-row" style={{ alignItems: "center" }}><span><strong>{customer.name}</strong><small style={{ display: "block", color: "var(--muted)" }}>{customer.email}</small></span><div style={{ display: "flex", gap: 8, alignItems: "center", flexWrap: "wrap", justifyContent: "flex-end" }}><select className="search-input" aria-label={`Mức giá cho ${customer.name}`} value={customerLevels[String(customer.id)] ?? defaultLevel} onChange={(event) => setCustomerLevels({ ...customerLevels, [String(customer.id)]: event.target.value })}>{Object.entries(settings.levels).map(([key, level]) => <option key={key} value={key}>{level.name}</option>)}</select><label><input type="checkbox" checked={vipIds.includes(customer.id)} onChange={() => toggleVip(customer.id)} /> VIP</label><button className="quiet-button" type="button" onClick={() => toggleCustomer(customer.id)}>Gỡ</button></div></div>)}{settings && !settings.customers.some((customer) => customerIds.includes(customer.id)) && <p className="muted">Chưa có khách sỉ trong danh sách.</p>}</div>
      </section>
      <section className="card section-card"><h2>Mức giá bán sỉ</h2><label htmlFor="wholesale-levels">Mã mức, tên và phần trăm chiết khấu (JSON)</label><textarea id="wholesale-levels" className="search-input" style={{ width: "100%", minHeight: 220, padding: 10, fontFamily: "monospace", fontSize: 12 }} value={levelsText} onChange={(event) => setLevelsText(event.target.value)} />
        <label htmlFor="wholesale-default-level" style={{ display: "block", marginTop: 12 }}>Mức mặc định</label><select id="wholesale-default-level" className="search-input" value={defaultLevel} onChange={(event) => setDefaultLevel(event.target.value)}>{Object.entries(settings?.levels ?? {}).map(([key, level]) => <option key={key} value={key}>{level.name} ({level.discount}%)</option>)}</select>
        <p className="muted">Giá sản phẩm sỉ được tính bởi plugin hiện có; thay đổi mức giá không sửa lại giá đã chụp vào đơn cũ.</p>
      </section>
    </div>
  </>;
}
