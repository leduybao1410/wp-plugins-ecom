"use client";

import { apiFetch } from "@/lib/api";

import { DatePicker } from "./date-picker";

import { useCallback, useEffect, useState } from "react";
import { useSearchParams } from "@/lib/router";
import { localToday, normalizeOverviewRange } from "@/lib/overview-query.js";

type LedgerRow = Record<string, unknown>;
type LedgerPage = { items?: LedgerRow[]; total?: number; totals?: Record<string, number | string | null> };

const columns = [
  ["entry_date", "Ngày"], ["order_code", "Mã đơn"], ["channel", "Kênh"], ["distributor_name", "Nhà phân phối"],
  ["product_name", "Sản phẩm"], ["quantity", "SL"], ["revenue", "Doanh thu"], ["gross_profit", "Lợi nhuận gộp"],
  ["commission_amount", "Hoa hồng"], ["net_profit", "Lợi nhuận ròng"],
] as const;

export function FinanceLedger() {
  const search = useSearchParams();
  const today = localToday();
  const [from, setFrom] = useState(search.get("from") ?? "");
  const [to, setTo] = useState(search.get("to") ?? today);
  const [distributor, setDistributor] = useState(search.get("distributor_id") ?? "");
  const [channel, setChannel] = useState(search.get("channel") ?? "");
  const [sort, setSort] = useState(search.get("sort") ?? "entry_date");
  const [order, setOrder] = useState(search.get("order") ?? "desc");
  const [page, setPage] = useState(Number(search.get("page") ?? "1") || 1);
  const [applied, setApplied] = useState({ from: search.get("from") ?? "", to: search.get("to") ?? today, distributor: search.get("distributor_id") ?? "", channel: search.get("channel") ?? "", sort: search.get("sort") ?? "entry_date", order: search.get("order") ?? "desc", page: Number(search.get("page") ?? "1") || 1 });
  const [data, setData] = useState<LedgerPage | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  const load = useCallback(async () => {
    setBusy(true); setError(""); setNotice("");
    try {
      const params = new URLSearchParams({ page: String(applied.page), per_page: "20", sort: applied.sort, order: applied.order });
      if (applied.from) params.set("from", applied.from);
      if (applied.to) params.set("to", applied.to);
      if (applied.distributor) params.set("distributor_id", applied.distributor);
      if (applied.channel.trim()) params.set("channel", applied.channel.trim());
      window.history.replaceState(null, "", `${window.location.pathname}?${params}`);
      const response = await apiFetch(`/api/dashboard/ledger?${params}`);
      const payload: LedgerPage & { error?: string } = await response.json();
      if (!response.ok) throw new Error(payload.error || "Không tải được sổ hoa hồng");
      setData(payload);
    } catch (e) { setError(e instanceof Error ? e.message : "Không tải được sổ hoa hồng"); }
    finally { setBusy(false); }
  }, [applied]);
  useEffect(() => { const timer = window.setTimeout(() => { void load(); }, 0); return () => window.clearTimeout(timer); }, [load]);

  function applyFilters() {
    setError("");
    try { if (from && to) normalizeOverviewRange({ from, to }); }
    catch (e) { setError(e instanceof Error ? e.message : "Khoảng thời gian không hợp lệ"); return; }
    if (distributor && !/^\d+$/.test(distributor)) { setError("Mã nhà phân phối phải là số."); return; }
    setPage(1); setApplied({ from, to, distributor, channel, sort, order, page: 1 });
  }

  async function exportCsv() {
    setBusy(true); setError("");
    try {
      const params = new URLSearchParams();
      if (applied.from) params.set("from", applied.from);
      if (applied.to) params.set("to", applied.to);
      if (applied.distributor) params.set("distributor_id", applied.distributor);
      if (applied.channel.trim()) params.set("channel", applied.channel.trim());
      const response = await apiFetch(`/api/dashboard/finance/export?${params}`);
      if (!response.ok) { const payload = await response.json(); throw new Error(payload.error || "Không xuất được dữ liệu"); }
      const blob = await response.blob(); const url = URL.createObjectURL(blob); const anchor = document.createElement("a");
      anchor.href = url; anchor.download = "epic-ledger.csv"; anchor.click(); URL.revokeObjectURL(url);
      setNotice("Đã tải tệp CSV theo bộ lọc hiện tại.");
    } catch (e) { setError(e instanceof Error ? e.message : "Không xuất được dữ liệu"); }
    finally { setBusy(false); }
  }

  function sortBy(field: string) {
    const nextOrder = sort === field && order === "asc" ? "desc" : "asc";
    setSort(field); setOrder(nextOrder); setPage(1); setApplied({ ...applied, sort: field, order: nextOrder, page: 1 });
  }

  return <>
    <div className="page-heading"><div><div className="eyebrow">EPIC / Tài chính</div><h1>Sổ hoa hồng</h1><p>Các khoản lợi nhuận và hoa hồng đã tính bởi plugin tài chính hiện có. Số liệu bán lẻ và bán sỉ được theo dõi riêng.</p></div><button className="quiet-button" type="button" disabled={busy} onClick={() => void exportCsv()}>Tải CSV theo bộ lọc</button></div>
    <div className="card toolbar finance-filters"><label htmlFor="ledger-from">Từ ngày</label><DatePicker id="ledger-from" label="Từ ngày" value={from} max={to || undefined} onChange={setFrom} /><label htmlFor="ledger-to">Đến ngày</label><DatePicker id="ledger-to" label="Đến ngày" value={to} min={from || undefined} onChange={setTo} /><label htmlFor="ledger-distributor">Mã nhà phân phối</label><input id="ledger-distributor" inputMode="numeric" className="search-input" value={distributor} onChange={(event) => setDistributor(event.target.value)} placeholder="Tất cả" /><label htmlFor="ledger-channel">Kênh</label><input id="ledger-channel" className="search-input" value={channel} onChange={(event) => setChannel(event.target.value)} placeholder="Tất cả kênh" /><button className="quiet-button" type="button" disabled={busy} onClick={applyFilters}>Lọc</button></div>
    {notice && <div className="success-box" role="status">{notice}</div>}{error && <div className="error-box" role="alert">{error}</div>}
    <section className="stat-grid finance-summary">{[["Doanh thu", "revenue"], ["Lợi nhuận gộp", "gross_profit"], ["Hoa hồng", "commission_amount"], ["Lợi nhuận ròng", "net_profit"]].map(([label, key]) => <div key={key} className="card stat-card"><span>{label}</span><strong>{formatMoney(data?.totals?.[key])}</strong></div>)}</section>
    <section className="card"><div className="toolbar"><span className="muted">{busy ? "Đang tải…" : `${(data?.total ?? 0).toLocaleString("vi-VN")} bản ghi`}</span><span className="muted">Cách tính do plugin tài chính hiện có cung cấp.</span></div><div className="table-wrap"><table><thead><tr>{columns.map(([key, label]) => <th key={key}>{["entry_date", "revenue", "gross_profit", "commission_amount", "net_profit"].includes(key) ? <button className="table-sort" onClick={() => sortBy(key)}>{label}{sort === key ? order === "asc" ? " ↑" : " ↓" : ""}</button> : label}</th>)}</tr></thead><tbody>{data?.items?.map((row, index) => <tr key={String(row.id ?? index)}>{columns.map(([key]) => <td key={key}>{key.includes("date") ? String(row[key] ?? "—") : ["revenue", "gross_profit", "commission_amount", "net_profit"].includes(key) ? formatMoney(row[key] as number | string | null) : String(row[key] ?? "—")}</td>)}</tr>)}{!busy && !data?.items?.length && <tr><td colSpan={columns.length} className="empty">Không có dữ liệu trong khoảng lọc.</td></tr>}</tbody></table></div><div className="toolbar"><span className="muted">Trang {page}</span><div style={{ display: "flex", gap: 8 }}><button className="quiet-button" disabled={page <= 1 || busy} onClick={() => { const next = page - 1; setPage(next); setApplied({ ...applied, page: next }); }}>Trước</button><button className="quiet-button" disabled={busy || (data?.total != null && page * 20 >= data.total)} onClick={() => { const next = page + 1; setPage(next); setApplied({ ...applied, page: next }); }}>Tiếp</button></div></div></section>
  </>;

}

function formatMoney(value: string | number | null | undefined) { return value == null || value === "" ? "—" : `${Number(value).toLocaleString("vi-VN")} ₫`; }
