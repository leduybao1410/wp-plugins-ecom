"use client";

import { apiFetch } from "@/lib/api";

import { useCallback, useEffect, useRef, useState } from "react";
import type { FormEvent } from "react";
import { useSearchParams } from "@/lib/router";
import Link from "@/lib/router";
import { reconciliationRequest } from "@/lib/shipment-reconciliation";
import { labelOptions, labelPrintRequest } from "@/lib/shipment-label.js";
import { ExistingShipmentLink } from "@/components/existing-shipment-link";
import { validateLeadDeletion } from "@/lib/business-workflows.js";
import { columnsForResource, readVisibleColumns, recordViews, writeVisibleColumns } from "@/lib/record-views.js";
import { appendProductRow, setProductTranslation, updateProductRow } from "@/lib/product-editor.js";
import { ManualOrderForm } from "@/components/manual-order-form";
import * as Dialog from "@radix-ui/react-dialog";
import { ArrowRight, Check, ChevronDown, ChevronLeft, ChevronRight, Copy, Plus, X } from "lucide-react";

type RecordRow = Record<string, unknown>;
type PageResult = { items?: RecordRow[]; total?: number | null; partial?: boolean };
const labels: Record<string, string> = {
  id: "Mã", number: "Mã đơn", name: "Tên", title: "Tiêu đề", date: "Ngày", paid_at: "Đã thanh toán lúc", modified: "Cập nhật", status: "Trạng thái", email_status: "Trạng thái gửi email", shipment_status: "Trạng thái vận chuyển", total: "Tổng tiền", currency: "Tiền tệ", customer: "Khách hàng", email: "Email", payment_method: "Thanh toán", transaction_id: "Mã giao dịch", shipping: "Vận chuyển", tracking: "Mã vận đơn", type: "Loại", sku: "SKU", price: "Giá", quantity: "Số lượng", stock: "Tồn kho", stock_quantity: "Số lượng tồn", orders: "Số đơn", spent: "Đã mua", company: "Công ty", phone: "Điện thoại", rating: "Điểm", product_id: "Mã sản phẩm", product_name: "Sản phẩm", author: "Người đánh giá", content: "Nội dung đánh giá", locale: "Ngôn ngữ", linked_account: "Tài khoản đã liên kết", wholesale_eligible: "Đủ điều kiện bán sỉ", created_at: "Tạo lúc", source: "Nguồn", unit_cost: "Giá vốn", updated_at: "Cập nhật lúc", distributor_name: "Nhà phân phối", amount: "Số tiền", order_id: "Mã đơn", tracking_url: "Theo dõi vận đơn", status_date: "Cập nhật trạng thái lúc", last_synced: "Đồng bộ lần cuối", service: "Dịch vụ vận chuyển", province: "Tỉnh/Thành giao", cod_amount: "Tiền thu hộ (COD)", courier_fee: "Phí vận chuyển ViettelPost", courier_base_fee: "Cước chính ViettelPost", fuel_surcharge: "Phụ phí xăng dầu", vat: "VAT", quoted_shipping: "Phí vận chuyển đã thu", needs_action: "Cần xử lý", is_cod: "Thu hộ (COD)",   shipping_method: "Phương thức vận chuyển", recipient_name: "Người nhận", recipient_phone: "SĐT người nhận", fulfillment: "Hình thức giao", override_reason: "Lý do sửa giá", unit_price: "Đơn giá", item_id: "Mã dòng", coupon_codes: "Mã giảm giá", manual_discount: "Giảm giá thủ công", customer_note: "Ghi chú giao hàng",
};
const sourceLabels: Record<string, string> = { direct: "Điện thoại / Direct" };
const statusLabels: Record<string, string> = { processing: "Đang xử lý", "on-hold": "Tạm giữ", pending: "Đang chờ", completed: "Hoàn tất", cancelled: "Đã hủy", refunded: "Đã hoàn tiền", failed: "Thất bại", draft: "Bản nháp", publish: "Đã xuất bản", private: "Riêng tư", approved: "Đã duyệt", unapproved: "Không duyệt", done: "Hoàn tất", sent: "Đã gửi" };
const hidden = new Set(["password", "token", "secret", "content", "excerpt", "description", "fields", "response", "_source", "revision", "reconciliation", "status_options", "label_options", "tracking_history", "cost_breakdown", "courier_base_fee", "fuel_surcharge", "vat", "province_id", "ward_id", "ward_name", "recent_orders", "items", "notes", "shipment", "weight", "dimensions", "shipping_required", "shipping_taxable", "shipping_class", "shipping_class_id", "customer_note"]);
const statusChoices: Record<string, { value: string; label: string }[]> = {
  orders: [{ value: "processing", label: "Đang xử lý" }, { value: "on-hold", label: "Tạm giữ" }, { value: "pending", label: "Chờ thanh toán" }, { value: "completed", label: "Hoàn tất" }, { value: "cancelled", label: "Đã hủy" }, { value: "refunded", label: "Đã hoàn tiền" }, { value: "failed", label: "Thất bại" }],
  content: [{ value: "publish", label: "Đã xuất bản" }, { value: "draft", label: "Bản nháp" }, { value: "pending", label: "Chờ duyệt" }, { value: "private", label: "Riêng tư" }],
  leads: [{ value: "pending", label: "Email chờ gửi" }, { value: "sent", label: "Đã gửi email" }, { value: "failed", label: "Gửi email lỗi" }],
  "wholesale-orders": [{ value: "pending", label: "Chờ xử lý" }, { value: "approved", label: "Đã duyệt" }, { value: "done", label: "Hoàn tất" }, { value: "unapproved", label: "Không duyệt" }],
  marketing: [{ value: "pending", label: "Chờ gửi email" }, { value: "sent", label: "Đã gửi email" }, { value: "failed", label: "Gửi email lỗi" }],
  reviews: [{ value: "pending", label: "Chờ duyệt" }, { value: "approved", label: "Đã duyệt" }],
};

export function RecordsPage({ module, title, resource }: { module: string; title: string; resource: string }) {
  const searchParams = useSearchParams();
  const [query, setQuery] = useState(searchParams.get("search") ?? "");
  const queryRef = useRef(searchParams.get("search") ?? "");
  const [page, setPage] = useState(Number(searchParams.get("page") ?? "1") || 1);
  const [statusFilter, setStatusFilter] = useState(searchParams.get("status") ?? (module === "reviews" ? "pending" : ""));
  const [data, setData] = useState<PageResult | null>(null);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const [selected, setSelected] = useState<RecordRow | null>(null);
  const dataRef = useRef<PageResult | null>(null);
  const [recordDetail, setRecordDetail] = useState<RecordRow | null>(null);
  const [recordDetailBusy, setRecordDetailBusy] = useState(false);
  const [reconciliationTracking, setReconciliationTracking] = useState("");
  const [linkingShipment, setLinkingShipment] = useState(false);
  const [manualShipmentStatus, setManualShipmentStatus] = useState("");
  const [orderNote, setOrderNote] = useState("");
  const [labelShowPostage, setLabelShowPostage] = useState(true);
  const [notice, setNotice] = useState("");
  const [visibleColumns, setVisibleColumns] = useState<Record<string, string[]>>({});
  const [sourceFilter, setSourceFilter] = useState(searchParams.get("source") ?? "");
  const [creatingOrder, setCreatingOrder] = useState(false);
  const [editingOrder, setEditingOrder] = useState<RecordRow | null>(null);
  const triggerRef = useRef<HTMLElement | null>(null);
  const dirtyRef = useRef(false);
  const pushedDetailRef = useRef(false);
  const requestSeq = useRef(0);
  const detailRequestSeq = useRef(0);
  const endpoint = resource;

  const openRecord = useCallback(async (row: RecordRow, updateUrl = true) => {
    const requestId = ++detailRequestSeq.current;
    if (updateUrl) {
      const current = new URL(window.location.href);
      current.searchParams.set("record", String(row.id ?? ""));
      window.history.pushState(null, "", `${current.pathname}${current.search}`);
      pushedDetailRef.current = true;
    }
    setSelected(row); setRecordDetail(row); setRecordDetailBusy(Boolean(row.id)); setNotice(""); setReconciliationTracking(""); setLinkingShipment(false); setManualShipmentStatus(String(row.shipment_status_code ?? "")); setOrderNote(""); setLabelShowPostage(labelOptions(row).defaultShowPostage);
    if (row.id) {
      const detailsUrl = new URL(`/api/dashboard/${endpoint}/${row.id}`, window.location.origin);
      if (typeof row._source === "string") detailsUrl.searchParams.set("source", row._source);
      try {
        // apiFetch takes a path string; a URL object would throw before the request.
        const response = await apiFetch(`${detailsUrl.pathname}${detailsUrl.search}`);
        const payload = await response.json();
        if (requestId !== detailRequestSeq.current) return;
        if (response.ok) {
          const detail = module === "orders" ? normalizeOrderDetail(payload) : payload;
          setRecordDetail(detail);
          if (module === "products") setSelected(detail);
          if (typeof detail.shipment_status_code === "string") setManualShipmentStatus(detail.shipment_status_code);
          setLabelShowPostage(labelOptions(detail).defaultShowPostage);
          // Prefill the note editor with the last hand-written note, without
          // overwriting anything the user already typed.
          if (module === "orders") setOrderNote((current) => current || latestOrderNote(detail));
        }
        else setNotice(payload.error || "Không tải được chi tiết bản ghi");
      } catch {
        if (requestId === detailRequestSeq.current) setNotice("Không thể kết nối để tải chi tiết bản ghi.");
      } finally {
        if (requestId === detailRequestSeq.current) setRecordDetailBusy(false);
      }
    }
  }, [endpoint, module]);

  const load = useCallback(async (pageOverride = page, statusOverride = statusFilter) => {
    const requestId = ++requestSeq.current;
    setError(""); setBusy(true);
    const params = new URLSearchParams({ page: String(pageOverride), per_page: "20" });
    if (queryRef.current.trim()) params.set("search", queryRef.current.trim());
    if (statusOverride) params.set("status", statusOverride);
    if (resource === "orders" && sourceFilter) params.set("source", sourceFilter);
    if (resource === "newsletter" && module === "marketing") params.set("source", "epic_newsletter_subscribers");
    const url = new URL(window.location.href);
    for (const key of ["page", "per_page", "search", "status", "source"]) url.searchParams.delete(key);
    params.forEach((value, key) => url.searchParams.set(key, value));
    window.history.replaceState(null, "", `${url.pathname}${url.search}`);
    try {
      const response = await apiFetch(`/api/dashboard/${endpoint}?${params}`);
      const payload = await response.json();
      if (!response.ok) throw new Error(payload.error || "Không tải được dữ liệu");
      if (requestId === requestSeq.current) {
        setData(payload); dataRef.current = payload;
        const recordId = new URL(window.location.href).searchParams.get("record");
        const row = recordId && payload.items?.find((item: RecordRow) => String(item.id ?? "") === recordId);
        if (row) void openRecord(row, false);
        else if ((module === "shipments" || module === "products") && recordId && /^\d+$/.test(recordId)) void openRecord({ id: Number(recordId), number: `#${recordId}` }, false);
      }
    } catch (e) { if (requestId === requestSeq.current) setError(e instanceof Error ? e.message : "Không tải được dữ liệu"); }
    finally { if (requestId === requestSeq.current) setBusy(false); }
  }, [page, endpoint, module, resource, statusFilter, sourceFilter, openRecord]);
  useEffect(() => { const timer = window.setTimeout(() => { void load(); }, 0); return () => window.clearTimeout(timer); }, [load]);

  const keys = columnsForResource(resource, data?.items?.[0]).filter((key) => !hidden.has(key));
  const availableColumns = keys.join("|");
  const visibleKeys = visibleColumns[resource] ? keys.filter((key) => visibleColumns[resource].includes(key)) : keys;
  useEffect(() => {
    const timer = window.setTimeout(() => {
      const resourceColumns = recordViews[resource as keyof typeof recordViews]?.columns ?? [];
      const defaults = resourceColumns.filter((key) => !hidden.has(key));
      try { setVisibleColumns((current) => ({ ...current, [resource]: readVisibleColumns(window.localStorage, resource, defaults) })); }
      catch { setVisibleColumns((current) => ({ ...current, [resource]: availableColumns ? availableColumns.split("|") : defaults })); }
    }, 0);
    return () => window.clearTimeout(timer);
  }, [resource, availableColumns]);

  function closeRecord() {
    if (dirtyRef.current && !window.confirm("Bạn có thay đổi chưa lưu. Đóng và bỏ các thay đổi này?")) return;
    dirtyRef.current = false;
    detailRequestSeq.current += 1;
    setSelected(null); setRecordDetail(null); setRecordDetailBusy(false);
    const current = new URL(window.location.href);
    if (pushedDetailRef.current) { pushedDetailRef.current = false; window.history.back(); }
    else if (current.searchParams.has("record")) { current.searchParams.delete("record"); window.history.replaceState(null, "", `${current.pathname}${current.search}`); }
    triggerRef.current?.focus();
  }
  useEffect(() => {
    function onPopState() {
      const current = new URL(window.location.href);
      if (!current.searchParams.has("record")) {
        if (dirtyRef.current && !window.confirm("Bạn có thay đổi chưa lưu. Đóng và bỏ các thay đổi này?")) {
          const restore = new URL(window.location.href);
          restore.searchParams.set("record", String(selected?.id ?? ""));
          window.history.pushState(null, "", `${restore.pathname}${restore.search}`);
          return;
        }
        dirtyRef.current = false; detailRequestSeq.current += 1; pushedDetailRef.current = false; setSelected(null); setRecordDetail(null); setRecordDetailBusy(false); triggerRef.current?.focus();
      }
      else {
        const recordId = current.searchParams.get("record");
        const row = dataRef.current?.items?.find((item) => String(item.id ?? "") === recordId);
        if (row) void openRecord(row, false);
        else if (module === "products" && recordId && /^\d+$/.test(recordId)) void openRecord({ id: Number(recordId), number: `#${recordId}` }, false);
      }
      const nextPage = Math.max(1, Number(current.searchParams.get("page") ?? "1") || 1);
      const nextStatus = current.searchParams.get("status") ?? (module === "reviews" ? "pending" : "");
      const nextSearch = current.searchParams.get("search") ?? "";
      if (resource === "orders") setSourceFilter(current.searchParams.get("source") ?? "");
      queryRef.current = nextSearch; setQuery(nextSearch); setPage(nextPage); setStatusFilter(nextStatus);
      void load(nextPage, nextStatus);
    }
    window.addEventListener("popstate", onPopState);
    return () => window.removeEventListener("popstate", onPopState);
  }, [module, resource, load, openRecord, selected?.id]);
  async function refreshShipmentDetail() {
    if (!selected?.id) return;
    const response = await apiFetch(`/api/dashboard/shipments/${selected.id}`);
    if (response.ok) { const payload = await response.json(); setRecordDetail(payload); if (typeof payload.shipment_status_code === "string") setManualShipmentStatus(payload.shipment_status_code); setLabelShowPostage(labelOptions(payload).defaultShowPostage); }
  }
  /** Re-read the open record so a retry uses the latest revision. */
  async function reloadRecordDetail() {
    if (!selected?.id) return;
    try {
      const response = await apiFetch(`/api/dashboard/${endpoint}/${selected.id}`);
      if (!response.ok) return;
      const payload = await response.json();
      setRecordDetail(module === "orders" ? normalizeOrderDetail(payload) : payload);
      if (typeof payload.shipment_status_code === "string") setManualShipmentStatus(payload.shipment_status_code);
    } catch { /* keep the current detail; the notice already explains the failure */ }
  }
  function writeListUrl(nextPage: number, nextStatus: string, nextSearch: string, push = false) {
    const url = new URL(window.location.href);
    url.searchParams.set("page", String(nextPage)); url.searchParams.set("per_page", "20");
    if (nextStatus) url.searchParams.set("status", nextStatus); else url.searchParams.delete("status");
    if (nextSearch.trim()) url.searchParams.set("search", nextSearch.trim()); else url.searchParams.delete("search");
    if (resource === "orders") { if (sourceFilter) url.searchParams.set("source", sourceFilter); else url.searchParams.delete("source"); }
    if (resource === "newsletter" && module === "marketing") url.searchParams.set("source", "epic_newsletter_subscribers");
    const method = push ? "pushState" : "replaceState";
    window.history[method](null, "", `${url.pathname}${url.search}`);
  }
  function setListPage(nextPage: number) { setPage(nextPage); writeListUrl(nextPage, statusFilter, queryRef.current, true); }
  async function submitSearch(event: FormEvent) { event.preventDefault(); queryRef.current = query; setPage(1); writeListUrl(1, statusFilter, query, true); await load(1); }
  async function updateRecord(changes: Record<string, unknown>): Promise<{ ok: boolean; status: number; message: string }> {
    if (!selected?.id) return { ok: false, status: 0, message: "" };
    setBusy(true); setNotice("");
    try {
      const response = await apiFetch(`/api/dashboard/${endpoint}/${selected.id}`, { method: "PATCH", headers: { "Content-Type": "application/json" }, body: JSON.stringify(changes) });
      const payload = await response.json();
      if (!response.ok) {
        // WordPress REST errors carry `message`; the BFF uses `error`.
        const message = payload.error || payload.message || "Không lưu được thay đổi";
        setNotice(message);
        return { ok: false, status: response.status, message };
      }
      dirtyRef.current = false;
      await load();
      const refreshed = await apiFetch(`/api/dashboard/${endpoint}/${selected.id}`);
      if (refreshed.ok) { const detail = await refreshed.json(); setRecordDetail(module === "orders" ? normalizeOrderDetail(detail) : detail); }
      setNotice("Đã lưu thay đổi.");
      return { ok: true, status: response.status, message: "" };
    } catch (e) {
      const message = e instanceof Error ? e.message : "Không lưu được thay đổi";
      setNotice(message);
      return { ok: false, status: 0, message };
    } finally { setBusy(false); }
  }
  async function deleteRecord() {
    if (!selected?.id || !window.confirm("Xóa đánh giá này khỏi hồ sơ sản phẩm? Thao tác này không thể hoàn tác.")) return;
    setBusy(true); setNotice("");
    try {
      const response = await apiFetch(`/api/dashboard/${endpoint}/${selected.id}`, { method: "DELETE" });
      const payload = await response.json();
      if (!response.ok) throw new Error(payload.error || "Không xóa được đánh giá");
      setSelected(null); setRecordDetail(null); setNotice("Đã xóa đánh giá."); await load();
    } catch (e) { setNotice(e instanceof Error ? e.message : "Không xóa được đánh giá"); }
    finally { setBusy(false); }
  }
  async function deleteLead() {
    if (!selected?.id || typeof recordDetail?.revision !== "string" || typeof recordDetail?._source !== "string") return;
    if (!window.confirm("Bạn đã rà soát thông tin khách hàng và muốn xóa vĩnh viễn yêu cầu này khỏi WordPress?")) return;
    let body: ReturnType<typeof validateLeadDeletion>;
    try { body = validateLeadDeletion({ expected_revision: recordDetail.revision, confirm_delete: true }); }
    catch (e) { setError(e instanceof Error ? e.message : "Hãy tải lại yêu cầu trước khi xóa"); return; }
    setBusy(true); setError("");
    try {
      const response = await apiFetch(`/api/dashboard/leads/${selected.id}?source=${encodeURIComponent(recordDetail._source)}`, { method: "DELETE", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) });
      const payload = await response.json();
      if (!response.ok) throw new Error(payload.error || "Không xóa được yêu cầu");
      setSelected(null); setRecordDetail(null); setNotice("Đã xóa yêu cầu theo thao tác có sẵn trong WordPress."); await load();
    } catch (e) { setError(e instanceof Error ? e.message : "Không xóa được yêu cầu"); }
    finally { setBusy(false); }
  }
  async function shipmentAction(action: "book" | "cancel" | "label" | "status", options?: string | { size?: string; showPostage?: boolean }) {
    if (!selected?.id) return;
    if ((action === "book" || action === "cancel" || action === "status") && !window.confirm(action === "book" ? "Xác nhận tạo vận đơn ViettelPost cho đơn này?" : action === "cancel" ? "Xác nhận hủy vận đơn đang có?" : "Xác nhận cập nhật trạng thái vận đơn thủ công?")) return;
    const status = typeof options === "string" ? options : undefined;
    const label = action === "label" && options && typeof options === "object" ? options : undefined;
    let body: Record<string, unknown> = { action, ...(action === "status" ? { status } : {}) };
    if (action === "label") {
      try { body = labelPrintRequest({ size: label?.size, showPostage: label?.showPostage }); }
      catch (e) { setNotice(e instanceof Error ? e.message : "Chọn khổ nhãn A5, A6 hoặc A7"); return; }
    }
    setBusy(true); setNotice("");
    try {
      const response = await apiFetch(`/api/dashboard/shipments/${selected.id}/action`, {
        method: "POST", headers: { "Content-Type": "application/json", ...(["book", "cancel"].includes(action) ? { "Idempotency-Key": crypto.randomUUID() } : {}) }, body: JSON.stringify(body),
      });
      const payload = await response.json();
      if (!response.ok) throw new Error(payload.error || "Không hoàn tất được thao tác vận chuyển");
      if (action === "label" && payload.url) window.open(payload.url, "_blank", "noopener,noreferrer");
      setNotice(action === "book" ? "Đã tạo vận đơn." : action === "cancel" ? "Đã hủy vận đơn." : action === "status" ? "Đã cập nhật trạng thái vận đơn." : "Đã mở nhãn vận đơn.");
      await load();
      await refreshShipmentDetail();
    } catch (e) { setNotice(e instanceof Error ? e.message : "Không hoàn tất được thao tác vận chuyển"); await refreshShipmentDetail(); }
    finally { setBusy(false); }
  }
  async function linkExistingShipment(body: Record<string, unknown>) {
    if (!selected?.id) return;
    setBusy(true); setNotice("");
    try {
      const response = await apiFetch(`/api/dashboard/shipments/${selected.id}/action`, {
        method: "POST",
        headers: { "Content-Type": "application/json", "Idempotency-Key": crypto.randomUUID() },
        body: JSON.stringify(body),
      });
      const payload = await response.json();
      if (!response.ok) throw new Error(payload.error || "Không liên kết được vận đơn");
      setLinkingShipment(false);
      setNotice(`Đã liên kết vận đơn ${String(payload.trackingCode || body.tracking)} với đơn hàng.`);
      await load();
      if (resource === "shipments") await refreshShipmentDetail();
      else {
        const refreshed = await apiFetch(`/api/dashboard/${endpoint}/${selected.id}`);
        if (refreshed.ok) setRecordDetail(await refreshed.json());
      }
    } catch (e) {
      setNotice(e instanceof Error ? e.message : "Không liên kết được vận đơn");
    } finally { setBusy(false); }
  }
  async function reconcileShipment(outcome: "booked" | "not_booked" | "cancelled" | "not_cancelled") {
    if (!selected?.id || !recordDetail) return;
    const action = String((recordDetail.reconciliation as RecordRow | undefined)?.action ?? "");
    let body: Record<string, unknown>;
    try { body = reconciliationRequest(action, outcome, reconciliationTracking); }
    catch (e) { setNotice(e instanceof Error ? e.message : "Thông tin đối soát không hợp lệ"); return; }
    const confirmation = outcome === "booked"
      ? "Xác nhận mã vận đơn này đã được ViettelPost cấp cho đơn hàng?"
      : outcome === "not_booked"
        ? "Chỉ xác nhận sau khi đã kiểm tra ViettelPost và chắc chắn chưa có vận đơn."
        : outcome === "cancelled"
          ? "Xác nhận ViettelPost đã hủy vận đơn này?"
          : "Chỉ xác nhận sau khi đã kiểm tra ViettelPost và chắc chắn vận đơn vẫn còn hiệu lực.";
    if (!window.confirm(confirmation)) return;
    setBusy(true); setNotice("");
    try {
      const response = await apiFetch(`/api/dashboard/shipments/${selected.id}/action`, {
        method: "POST",
        headers: { "Content-Type": "application/json", "Idempotency-Key": crypto.randomUUID() },
        body: JSON.stringify(body),
      });
      const payload = await response.json();
      if (!response.ok) throw new Error(payload.error || "Không đối soát được vận đơn");
      setNotice(payload.ready_to_retry ? "Đã xác nhận chưa hoàn tất thao tác. Bạn có thể thử lại sau khi rà soát đơn." : "Đã đối soát trạng thái vận đơn.");
      setReconciliationTracking("");
      await load();
      await refreshShipmentDetail();
    } catch (e) { setNotice(e instanceof Error ? e.message : "Không đối soát được vận đơn"); }
    finally { setBusy(false); }
  }
  const reconciliation = recordDetail?.reconciliation && typeof recordDetail.reconciliation === "object"
    ? recordDetail.reconciliation as RecordRow
    : null;
  const orderShipment = recordDetail?.shipment && typeof recordDetail.shipment === "object"
    ? recordDetail.shipment as RecordRow
    : null;

  return <>
    <div className="page-heading"><div><div className="eyebrow">EPIC / Quản trị</div><h1>{title}</h1><p>{descriptions[module] || "Tìm kiếm, xem và quản lý dữ liệu vận hành."}</p></div></div>
    {notice && <div className="success-box" role="status">{notice}</div>}
    {error && <div className="error-box" role="alert">{error}</div>}
    <section className="card records-card">
      <div className="toolbar records-toolbar"><form className="records-filter-form" onSubmit={submitSearch}><label htmlFor="record-search" className="sr-only">Tìm kiếm</label><input id="record-search" className="search-input" value={query} onChange={(event) => { setQuery(event.target.value); queryRef.current = event.target.value; }} placeholder="Tìm theo tên, mã hoặc email…"/>{statusChoices[module] && <><label htmlFor="record-status" className="sr-only">Lọc trạng thái</label><select id="record-status" className="search-input" value={statusFilter} onChange={(event) => { const value = event.target.value; setStatusFilter(value); setPage(1); writeListUrl(1, value, queryRef.current, true); void load(1, value); }}><option value="">Tất cả trạng thái</option>{statusChoices[module].map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}</select></>}<button className="quiet-button" type="submit">Tìm kiếm</button></form>{module === "orders" && <><label htmlFor="record-source" className="sr-only">Lọc nguồn</label><select id="record-source" className="search-input" value={sourceFilter} onChange={(event) => { setSourceFilter(event.target.value); setPage(1); }}><option value="">Tất cả nguồn</option><option value="direct">Điện thoại / Direct</option></select><button className="quiet-button" type="button" onClick={() => setCreatingOrder(true)}><Plus size={15} aria-hidden="true" /> Tạo đơn trực tiếp</button></>}<span className="muted" aria-live="polite" aria-busy={busy}>{busy ? "Đang tải…" : data?.total == null ? `${data?.items?.length ?? 0} bản ghi` : `${data.total.toLocaleString("vi-VN")} bản ghi`}</span><details className="column-menu"><summary aria-label="Chọn cột hiển thị">Cột</summary><fieldset><legend>Cột hiển thị</legend>{keys.map((key) => <label key={key}><input type="checkbox" checked={visibleKeys.includes(key)} disabled={visibleKeys.length === 1 && visibleKeys.includes(key)} onChange={(event) => { const next = event.target.checked ? [...visibleKeys, key] : visibleKeys.filter((column) => column !== key); writeVisibleColumns(window.localStorage, resource, next); setVisibleColumns((current) => ({ ...current, [resource]: next })); }} />{labels[key] || key.replaceAll("_", " ")}</label>)}</fieldset></details></div>
      <div className="table-wrap records-table-wrap"><table><thead><tr>{visibleKeys.map((key) => <th key={key}>{labels[key] || key.replaceAll("_", " ")}</th>)}<th><span className="sr-only">Chi tiết</span></th></tr></thead><tbody>
        {data?.items?.map((row, index) => <tr key={String(row.id ?? index)}>{visibleKeys.map((key) => { const cell = key === "total" && row.currency ? formatMoney(row[key], row.currency) : resource === "shipments" && key === "tracking" && row.tracking_url ? <a href={String(row.tracking_url)} target="_blank" rel="noreferrer">{String(row[key] ?? "")}</a> : key === "shipment_status" ? <ShipmentStatusBadge code={String(row.shipment_status_code ?? "")} /> : formatCell(key, row[key]); const copyable = row[key] != null && row[key] !== "" && ((resource === "orders" && key === "number") || (resource === "products" && key === "sku")); return <td key={key}>{copyable ? <span className="cell-with-copy" style={{ display: "inline-flex", alignItems: "center", gap: 6 }}>{cell}<CopyValueButton value={String(row[key])} /></span> : cell}</td>; })}<td><button className="quiet-button" type="button" onClick={(event) => { triggerRef.current = event.currentTarget; void openRecord(row); }} aria-label={`Xem chi tiết ${row.number || row.title || row.name || row.id || "bản ghi"}`}>Chi tiết</button></td></tr>)}
        {!busy && !data?.items?.length && <tr><td colSpan={Math.max(1, visibleKeys.length + 1)} className="empty">Chưa có dữ liệu phù hợp.</td></tr>}
      </tbody></table></div>
      <div className="records-mobile-list">{data?.items?.map((row, index) => { const titleKey = visibleKeys.find((key) => ["number", "title", "name", "author"].includes(key) && row[key] != null) ?? visibleKeys[0]; return <button className="record-mobile-card" type="button" key={String(row.id ?? index)} onClick={(event) => { triggerRef.current = event.currentTarget; void openRecord(row); }}><span className="record-mobile-main"><strong>{String(row[titleKey] ?? "Bản ghi")}</strong><small className="record-mobile-subline">{visibleKeys.filter((key) => key !== titleKey).slice(0, 3).map((key) => <span key={key}>{labels[key] || key}: {key === "total" && row.currency ? formatMoney(row[key], row.currency) : formatCell(key, row[key])}</span>)}</small></span><ArrowRight size={17} aria-hidden="true" /></button>; })}{!busy && !data?.items?.length && <p className="empty-state">Chưa có dữ liệu phù hợp.</p>}</div>
      <div className="toolbar records-pagination"><span className="muted">Trang {page}{data?.total != null ? ` · ${data.total.toLocaleString("vi-VN")} bản ghi` : ""}</span><div className="pagination-actions"><button className="quiet-button" disabled={page <= 1 || busy} aria-label="Trang trước" onClick={() => setListPage(page - 1)}><ChevronLeft size={16} /> Trước</button><button className="quiet-button" disabled={busy || (data?.total != null ? page * 20 >= data.total : data?.items?.length !== 20)} aria-label="Trang sau" onClick={() => setListPage(page + 1)}>Tiếp <ChevronRight size={16} /></button></div></div>
    </section>
    {selected && <Dialog.Root open={Boolean(selected)} onOpenChange={(open) => { if (!open) closeRecord(); }}>
      <Dialog.Portal><Dialog.Overlay className="detail-backdrop" /><Dialog.Content className="detail-panel" aria-describedby={undefined}>
        <div className="detail-header"><Dialog.Title asChild><h2 id="selected-record-title">{selected ? `Chi tiết ${String(selected.number || selected.title || selected.name || `#${selected.id}`)}` : "Chi tiết bản ghi"}</h2></Dialog.Title><Dialog.Close asChild><button className="icon-button" aria-label="Đóng chi tiết"><X size={18} /></button></Dialog.Close></div>
        {module === "products" && <ProductReview key={recordDetail?.translations ? `full-${String(selected.id)}` : `summary-${String(selected.id)}`} record={recordDetail ?? selected} busy={busy || recordDetailBusy} onError={setNotice} onDirtyChange={(dirty) => { dirtyRef.current = dirty; }} />}
        {recordDetail && <section className="detail-section"><h3>Thông tin bản ghi</h3>{Object.entries(recordDetail).filter(([key]) => !hidden.has(key) || module === "reviews" && key === "content").map(([key, value]) => <div key={key} className="alert-row"><span className="muted">{labels[key] || key.replaceAll("_", " ")}</span><strong style={{ maxWidth: key === "content" ? "75%" : "65%", textAlign: "right", wordBreak: "break-word", whiteSpace: key === "content" ? "pre-wrap" : undefined, fontWeight: key === "content" ? 400 : 600 }}>{formatDetailValue(key, value, recordDetail)}</strong></div>)}</section>}
        {module === "products" && <section className="detail-section"><h3>Vận chuyển</h3><a className="quiet-button" style={{ display: "inline-flex", alignItems: "center", gap: 7, textDecoration: "none" }} href="/shipments" target="_blank" rel="noreferrer">Mở trang vận chuyển <ArrowRight size={15} aria-hidden="true" /></a></section>}
        {module === "orders" && <section className="detail-section"><h3>Vận chuyển</h3>{String(recordDetail?.customer_note || "").trim() ? <div className="alert-row"><span className="muted">Ghi chú giao hàng</span><strong style={{ maxWidth: "65%", textAlign: "right", wordBreak: "break-word", whiteSpace: "pre-wrap", fontWeight: 400 }}>{String(recordDetail?.customer_note || "")}</strong></div> : null}{orderShipment ? <><div className="alert-row"><span className="muted">Trạng thái vận chuyển</span><strong><ShipmentStatusBadge code={String(orderShipment.status_code ?? "")} /></strong></div>{orderShipment.tracking ? <div className="alert-row"><span className="muted">Mã vận đơn</span><strong>{formatDetailValue("tracking", orderShipment.tracking, {})} {formatDetailValue("tracking_url", orderShipment.tracking_url, {})}</strong></div> : null}{orderShipment.expected_delivery ? <div className="alert-row"><span className="muted">Dự kiến giao</span><strong>{formatCell("date", orderShipment.expected_delivery)}</strong></div> : null}{orderShipment.last_synced ? <div className="alert-row"><span className="muted">Đồng bộ lần cuối</span><strong>{formatCell("date", orderShipment.last_synced)}</strong></div> : null}{orderShipment.needs_action ? <div className="alert-row"><span className="muted">Cần xử lý</span><strong>{String(orderShipment.needs_action)}</strong></div> : null}</> : <p className="muted">Đơn chưa có vận đơn ViettelPost.</p>}{!orderShipment?.tracking && <ExistingShipmentLink record={recordDetail ?? selected} busy={busy} open={linkingShipment} onOpenChange={setLinkingShipment} onError={setNotice} onSubmit={linkExistingShipment} />}<a className="quiet-button" style={{ display: "inline-flex", alignItems: "center", gap: 7, textDecoration: "none" }} href={`/shipments?search=${encodeURIComponent(String(recordDetail?.number ?? selected.number ?? ""))}&record=${encodeURIComponent(String(selected.id ?? ""))}`} target="_blank" rel="noreferrer">Xem chi tiết vận chuyển <ArrowRight size={15} aria-hidden="true" /></a></section>}
        {module === "customers" && Array.isArray(recordDetail?.recent_orders) && <section className="detail-section"><h3>Lịch sử 20 đơn gần nhất</h3>{(recordDetail.recent_orders as RecordRow[]).map((order) => <div key={String(order.id)} className="alert-row"><span><strong>{String(order.number || `#${order.id}`)}</strong><small style={{ display: "block", color: "var(--muted)", marginTop: 3 }}>{formatCell("date", order.date)} · {String(order.status || "")}</small></span><strong>{formatMoney(order.total, order.currency || "VND")}</strong></div>)}{recordDetail.recent_orders.length === 0 && <p className="muted">Khách hàng chưa có đơn mua hàng.</p>}</section>}
		{module === "orders" && recordDetail && <section className="detail-section">
          <h3>Sản phẩm trong đơn</h3>
          {recordDetailBusy ? <p className="muted" role="status">Đang tải sản phẩm…</p> : Array.isArray(recordDetail.items) && recordDetail.items.length > 0 ? (recordDetail.items as RecordRow[]).map((item, index) => {
            const productId = Number(item.product_id);
            const productHref = Number.isInteger(productId) && productId > 0 ? `/products?record=${productId}` : "";
            return <div key={`${String(item.item_id ?? item.product_id)}-${index}`} className="alert-row" style={{ alignItems: "flex-start", gap: 12 }}>
              <span style={{ minWidth: 0 }}>
                {productHref ? <Link href={productHref} target="_blank" rel="noreferrer" prefetch={false} aria-label={`${String(item.name || "Sản phẩm")}, mở chi tiết trong thẻ mới`} style={{ fontWeight: 600 }}>{String(item.name || "Sản phẩm")} <span aria-hidden="true">↗</span></Link> : <strong>{String(item.name || "Sản phẩm")}</strong>}
                {item.kind === "custom" ? <small style={{ display: "block", color: "var(--muted)", marginTop: 4 }}>{Number(item.package_size_g) === 1000 ? "1 kg" : `${String(item.package_size_g || 0)} g`} mỗi gói · {String(item.quantity ?? 0)} gói{String(item.recipe || "").trim() ? ` · Tỷ lệ pha: ${String(item.recipe)}` : ""}</small> : <small style={{ display: "block", color: "var(--muted)", marginTop: 4 }}>SKU {String(item.sku || "—")}{Number(item.variation_id) > 0 ? ` · Biến thể #${String(item.variation_id)}` : ""} · SL {String(item.quantity ?? 0)}</small>}
                <small style={{ display: "block", color: "var(--muted)", marginTop: 3 }}>Đơn giá sau giảm, chưa gồm thuế: {formatMoney(item.unit_price, recordDetail.currency)}</small>
              </span>
              <strong style={{ textAlign: "right", whiteSpace: "nowrap" }}>{formatMoney(item.total, recordDetail.currency)}</strong>
            </div>;
          }) : Array.isArray(recordDetail.items) ? <p className="muted">Đơn hàng chưa có sản phẩm.</p> : <p className="muted">Không có dữ liệu sản phẩm cho đơn hàng này.</p>}
        </section>}
		{module === "wholesale-orders" && Array.isArray(recordDetail?.items) && <section style={{ marginTop: 18 }}><h3 style={{ fontSize: 14 }}>Sản phẩm trong đơn bán sỉ</h3>{(recordDetail.items as RecordRow[]).map((item, index) => <div key={`${String(item.product_id)}-${index}`} className="alert-row"><span><strong>{String(item.name || "Sản phẩm")}</strong><small style={{ display: "block", color: "var(--muted)", marginTop: 3 }}>SKU {String(item.sku || "—")} · {String(item.quantity || 0)} sản phẩm · {String(item.unit_price || "Chưa chốt giá")}</small></span><strong>{item.line_total == null || item.line_total === "" ? "—" : Number(item.line_total).toLocaleString("vi-VN")}</strong></div>)}<p className="muted">Tổng đơn bán sỉ: {recordDetail.total == null ? "Chưa chốt giá" : Number(recordDetail.total).toLocaleString("vi-VN")}</p></section>}
        {module === "orders" && Array.isArray(recordDetail?.notes) && <section style={{ marginTop: 18 }}><h3 style={{ fontSize: 14 }}>Ghi chú đơn hàng</h3>{(recordDetail.notes as RecordRow[]).map((note, index) => <div key={String(note.id ?? index)} className="alert-row" style={{ display: "block" }}><small className="muted">{formatCell("date", noteTimestamp(note.date_created))}{note.customer_note ? " · Gửi cho khách" : " · Nội bộ"}</small><p style={{ margin: "6px 0 0", whiteSpace: "pre-wrap" }}>{String(note.content || "")}</p></div>)}{recordDetail.notes.length === 0 && <p className="muted">Chưa có ghi chú.</p>}</section>}
		{module === "orders" && <div className="detail-actions record-edit-actions" onChange={() => { dirtyRef.current = true; }} key={String(recordDetail?.revision ?? selected.id)}><label htmlFor="order-status">Trạng thái đơn hàng</label><select id="order-status" className="search-input" defaultValue={String(recordDetail?.status || "")}><option value="pending">Chờ thanh toán</option><option value="processing">Đang xử lý</option><option value="on-hold">Tạm giữ</option><option value="completed">Hoàn tất</option><option value="cancelled">Đã hủy</option><option value="refunded">Đã hoàn tiền</option><option value="failed">Thất bại</option></select><label htmlFor="order-note">Ghi chú đơn hàng</label><textarea id="order-note" className="search-input" style={{ height: 90, padding: 10 }} placeholder="Ghi chú nội bộ…" value={orderNote} onChange={(event) => { setOrderNote(event.target.value); dirtyRef.current = true; }}/><button className="action-button" disabled={busy || typeof recordDetail?.revision !== "string"} onClick={async () => { const status = (document.getElementById("order-status") as HTMLSelectElement).value; if (status !== String(recordDetail?.status) && ["completed", "cancelled"].includes(status) && !window.confirm(`Xác nhận chuyển đơn sang trạng thái “${status}”?`)) return; const existingNote = latestOrderNote(recordDetail); const note = orderNote.trim() && orderNote.trim() !== existingNote ? orderNote : ""; const result = await updateRecord({ status, note, expected_revision: recordDetail?.revision }); if (result.ok) return; if (result.status === 409) { await reloadRecordDetail(); setNotice("Đơn vừa được cập nhật ở nơi khác. Dữ liệu đã được làm mới — bấm “Lưu thay đổi” để lưu lại ghi chú."); } }}>Lưu thay đổi</button>{recordDetail?.source === "direct" && typeof recordDetail?.revision === "string" && <button className="quiet-button" type="button" disabled={busy} onClick={() => { if (dirtyRef.current && !window.confirm("Bạn có thay đổi chưa lưu. Mở trình sửa đơn và bỏ các thay đổi này?")) return; setEditingOrder(recordDetail); }}>Sửa đơn trực tiếp</button>}</div>}
		{module === "wholesale-orders" && <div className="detail-actions record-edit-actions" onChange={() => { dirtyRef.current = true; }} key={String(recordDetail?.revision ?? selected.id)}><label htmlFor="wholesale-order-status">Trạng thái đơn bán sỉ</label><select id="wholesale-order-status" className="search-input" defaultValue={String(recordDetail?.order_status_key || "pending")}>{Object.entries((recordDetail?.status_options ?? {}) as Record<string, string>).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select><label htmlFor="wholesale-unapprove-reason">Lý do không duyệt (bắt buộc nếu không duyệt)</label><textarea id="wholesale-unapprove-reason" className="search-input" style={{ height: 80, padding: 10 }} defaultValue={String(recordDetail?.cancel_reason || "")} /><button className="action-button" disabled={busy || typeof recordDetail?.revision !== "string"} onClick={() => { const orderStatus = (document.getElementById("wholesale-order-status") as HTMLSelectElement).value; const cancelReason = (document.getElementById("wholesale-unapprove-reason") as HTMLTextAreaElement).value; if (!window.confirm(`Xác nhận chuyển đơn bán sỉ sang trạng thái “${orderStatus}”?`)) return; void updateRecord({ order_status: orderStatus, cancel_reason: cancelReason, expected_revision: recordDetail?.revision }); }}>Lưu trạng thái bán sỉ</button><p className="muted">Trạng thái thanh toán: <strong>{String(recordDetail?.payment_status || "—")}</strong>. Dashboard chỉ xem thông tin thanh toán, không xác nhận đã thanh toán.</p>{recordDetail !== null && Boolean(recordDetail.has_invoice) && typeof recordDetail.invoice_edit_url === "string" && <a href={recordDetail.invoice_edit_url} target="_blank" rel="noreferrer">Mở hồ sơ hóa đơn trong wp-admin ↗</a>}</div>}
        {module === "reviews" && <div className="detail-actions record-edit-actions" style={{ display: "flex", gap: 8, marginTop: 22, flexWrap: "wrap" }}><button className="action-button" disabled={busy || typeof recordDetail?.revision !== "string"} onClick={() => void updateRecord({ status: "approved", expected_revision: recordDetail?.revision })}>Duyệt đánh giá</button><button className="quiet-button" disabled={busy || typeof recordDetail?.revision !== "string"} onClick={() => void updateRecord({ status: "pending", expected_revision: recordDetail?.revision })}>Giữ chờ duyệt</button><button className="quiet-button" disabled={busy} onClick={() => void deleteRecord()}>Xóa đánh giá</button></div>}
		{module === "leads" && <div className="detail-actions record-edit-actions" style={{ display: "flex", gap: 8, marginTop: 22 }}><button className="quiet-button" disabled={busy || typeof recordDetail?.revision !== "string" || typeof recordDetail?._source !== "string"} onClick={() => void deleteLead()}>Xóa yêu cầu đã rà soát</button><small className="muted">Xóa vĩnh viễn bản ghi PII trong nguồn WordPress hiện có.</small></div>}
        {module === "shipments" && String(recordDetail?.customer_note || "").trim() ? <section className="detail-section"><h3>Ghi chú giao hàng</h3><p style={{ margin: "6px 0 0", whiteSpace: "pre-wrap" }}>{String(recordDetail?.customer_note || "")}</p></section> : null}
        {module === "shipments" && Boolean(recordDetail?.cost_breakdown) && typeof recordDetail?.cost_breakdown === "object" && <ShipmentCostBreakdown key={`cost-${String(selected.id)}`} breakdown={recordDetail?.cost_breakdown as RecordRow} currency={String(recordDetail?.currency || "VND")} />}
        {module === "shipments" && <div style={{ display: "flex", gap: 8, marginTop: 22, flexWrap: "wrap" }}><button className="action-button" disabled={busy || Boolean(recordDetail?.tracking)} onClick={() => void shipmentAction("book")}>Tạo vận đơn</button>{!recordDetail?.tracking && <ExistingShipmentLink record={recordDetail ?? selected} busy={busy} open={linkingShipment} onOpenChange={setLinkingShipment} onError={setNotice} onSubmit={linkExistingShipment} />}{Boolean(recordDetail?.tracking) && <><details className="label-menu"><summary aria-label="Chọn khổ nhãn để in">In nhãn</summary><div className="label-menu-panel"><label><input type="checkbox" checked={labelShowPostage} disabled={busy} onChange={(event) => setLabelShowPostage(event.target.checked)} />In cước phí</label>{Object.entries(labelOptions(recordDetail ?? {}).sizes).map(([value, label]) => <button key={String(value)} className="quiet-button" type="button" disabled={busy} onClick={(event) => { void shipmentAction("label", { size: String(value), showPostage: labelShowPostage }); event.currentTarget.closest("details")?.removeAttribute("open"); }}>In {String(label)}</button>)}</div></details><button className="quiet-button" disabled={busy} onClick={() => void shipmentAction("cancel")}>Hủy vận đơn</button>{recordDetail?.status_options && <><label htmlFor="shipment-status" className="sr-only">Trạng thái vận đơn thủ công</label><select id="shipment-status" className="search-input" value={manualShipmentStatus} onChange={(event) => setManualShipmentStatus(event.target.value)}>{Object.entries(recordDetail.status_options as Record<string, string>).map(([code, label]) => <option key={code} value={code}>{code} · {label}</option>)}</select><button className="quiet-button" disabled={busy || !manualShipmentStatus} onClick={() => void shipmentAction("status", manualShipmentStatus)}>Cập nhật trạng thái</button></>}</>}<p className="muted" style={{ flexBasis: "100%" }}>Rà soát địa chỉ, tồn kho và trạng thái thanh toán trước khi xác nhận.</p></div>}
        {module === "shipments" && reconciliation && (() => {
          if (reconciliation.status === "pending") return <p className="error-box" role="status">Yêu cầu ViettelPost đang được xử lý. Hãy tải lại sau ít phút trước khi thực hiện thao tác khác.</p>;
          if (reconciliation.status === "multiple") return <p className="error-box" role="alert">Có nhiều thao tác vận đơn chưa đối soát. Hãy xử lý đơn này trong wp-admin trước khi tiếp tục.</p>;
          if (reconciliation.status !== "uncertain") return null;
          return <section className="card reconciliation-panel" aria-labelledby="shipment-reconcile-title">
            <h3 id="shipment-reconcile-title" style={{ marginTop: 0, fontSize: 15 }}>Cần đối soát ViettelPost</h3>
            <p className="muted">Kiểm tra đơn trong cổng ViettelPost trước. Hệ thống sẽ khóa thao tác mới đến khi bạn xác nhận kết quả.</p>
            {reconciliation.action === "book" ? <div style={{ display: "grid", gap: 9 }}>
              <label htmlFor="reconcile-tracking">Mã vận đơn nếu ViettelPost đã tạo</label>
              <input id="reconcile-tracking" className="search-input" value={reconciliationTracking} onChange={(event) => setReconciliationTracking(event.target.value)} autoComplete="off" />
              <button className="action-button" disabled={busy} onClick={() => void reconcileShipment("booked")}>Đã có vận đơn này</button>
              <button className="quiet-button" disabled={busy} onClick={() => void reconcileShipment("not_booked")}>Đã xác nhận chưa tạo vận đơn</button>
            </div> : reconciliation.action === "cancel" ? <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
              <button className="action-button" disabled={busy} onClick={() => void reconcileShipment("cancelled")}>Đã xác nhận hủy vận đơn</button>
              <button className="quiet-button" disabled={busy} onClick={() => void reconcileShipment("not_cancelled")}>Vận đơn vẫn còn hiệu lực</button>
            </div> : <p role="alert">Không nhận diện được thao tác cần đối soát. Hãy xử lý đơn này trong wp-admin.</p>}
          </section>;
        })()}
        {module === "shipments" && <ShipmentTimeline key={`journey-${String(selected.id)}`} history={Array.isArray(recordDetail?.tracking_history) ? recordDetail.tracking_history as RecordRow[] : []} />}

        {module === "content" && recordDetail && <ContentEditor key={String(recordDetail.revision)} record={recordDetail} busy={busy} onSave={updateRecord} onError={setNotice} onDirtyChange={(dirty) => { dirtyRef.current = dirty; }} />}
      </Dialog.Content></Dialog.Portal>
    </Dialog.Root>}
    {creatingOrder && <Dialog.Root open onOpenChange={(open) => { if (!open) setCreatingOrder(false); }}>
      <Dialog.Portal><Dialog.Overlay className="detail-backdrop" /><Dialog.Content className="detail-panel" aria-describedby={undefined}>
        <ManualOrderForm mode="create" onCancel={() => setCreatingOrder(false)} onError={setNotice} onSaved={() => { setCreatingOrder(false); setNotice("Đã tạo đơn trực tiếp."); void load(); }} />
      </Dialog.Content></Dialog.Portal>
    </Dialog.Root>}
    {editingOrder && <Dialog.Root open onOpenChange={(open) => { if (!open) setEditingOrder(null); }}>
      <Dialog.Portal><Dialog.Overlay className="detail-backdrop" /><Dialog.Content className="detail-panel" aria-describedby={undefined}>
        <ManualOrderForm mode="edit" order={editingOrder} onCancel={() => setEditingOrder(null)} onError={setNotice} onSaved={() => { const editedId = Number(editingOrder.id); setEditingOrder(null); setNotice("Đã lưu đơn trực tiếp."); void load(); if (selected?.id === editedId) void openRecord(selected, false); }} />
      </Dialog.Content></Dialog.Portal>
    </Dialog.Root>}
  </>;
}

function ProductReview({ record, busy, onError, onDirtyChange }: { record: RecordRow; busy: boolean; onError: (message: string) => void; onDirtyChange: (dirty: boolean) => void }) {
  const [preview, setPreview] = useState<Record<string, unknown> | null>(null);
  const product = record;
  const [name, setName] = useState(String(product.name ?? ""));
  const [regularPrice, setRegularPrice] = useState(String(product.regular_price ?? product.price ?? ""));
  const [salePrice, setSalePrice] = useState(String(product.sale_price ?? ""));
  const [sku, setSku] = useState(String(product.sku ?? ""));
  const [description, setDescription] = useState(String(product.description ?? ""));
  const [shortDescription, setShortDescription] = useState(String(product.short_description ?? ""));
  const [stockQuantity, setStockQuantity] = useState(String(product.stock_quantity ?? ""));
  const [stockStatus, setStockStatus] = useState(String(product.stock_status ?? "instock"));
  const [manageStock, setManageStock] = useState(Boolean(product.manage_stock));
  const [categories, setCategories] = useState<RecordRow[]>(Array.isArray(product.categories) ? product.categories as RecordRow[] : []);
  const [tags, setTags] = useState<RecordRow[]>(Array.isArray(product.tags) ? product.tags as RecordRow[] : []);
  const [images, setImages] = useState<RecordRow[]>(Array.isArray(product.images) ? product.images as RecordRow[] : []);
  const [attributes, setAttributes] = useState<RecordRow[]>(Array.isArray(product.attributes) ? product.attributes as RecordRow[] : []);
  const productMeta = Array.isArray(product.meta_data) ? product.meta_data as RecordRow[] : [];
  const wholesaleEnabled = productMeta.find((item) => item.key === "_epic_wholesale_enabled")?.value === "yes";
  const wholesalePrice = productMeta.find((item) => item.key === "_epic_wholesale_price")?.value ?? "";
  const [wholesaleIsEnabled, setWholesaleIsEnabled] = useState(wholesaleEnabled);
  const [wholesalePriceValue, setWholesalePriceValue] = useState(String(wholesalePrice));
  const [translations, setTranslations] = useState((record.translations && typeof record.translations === "object" ? record.translations : {}) as Record<string, Record<string, unknown>>);
  const [translationLocale, setTranslationLocale] = useState("vi");
  async function requestPreview(event: FormEvent) {
    event.preventDefault(); onError("");
    try {
      const changes: Record<string, unknown> = {};
      if (name !== String(product.name ?? "")) changes.name = name;
      if (regularPrice !== String(product.regular_price ?? product.price ?? "")) changes.regular_price = regularPrice;
      if (salePrice !== String(product.sale_price ?? "")) changes.sale_price = salePrice;
      if (sku !== String(product.sku ?? "")) changes.sku = sku;
      if (description !== String(product.description ?? "")) changes.description = description;
      if (shortDescription !== String(product.short_description ?? "")) changes.short_description = shortDescription;
      const stock = stockQuantity === "" ? null : Number(stockQuantity);
      if (stockQuantity !== String(product.stock_quantity ?? "")) changes.stock_quantity = stock;
      if (manageStock !== Boolean(product.manage_stock)) changes.manage_stock = manageStock;
      if (stockStatus !== String(product.stock_status ?? "instock")) changes.stock_status = stockStatus;
      if (JSON.stringify(categories) !== JSON.stringify(product.categories ?? [])) changes.categories = categories;
      if (JSON.stringify(tags) !== JSON.stringify(product.tags ?? [])) changes.tags = tags;
      if (JSON.stringify(images) !== JSON.stringify(product.images ?? [])) changes.images = images;
      if (JSON.stringify(attributes) !== JSON.stringify(product.attributes ?? [])) changes.attributes = attributes;
      if (wholesaleIsEnabled !== wholesaleEnabled || wholesalePriceValue !== String(wholesalePrice)) {
        changes.meta_data = [
          { key: "_epic_wholesale_enabled", value: wholesaleIsEnabled ? "yes" : "no" },
          { key: "_epic_wholesale_price", value: wholesalePriceValue },
        ];
      }
      const response = await apiFetch("/api/dashboard/product-change/preview", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ action: "update", resource_type: "product", id: Number(product.id), changes, translations }) });
      const result = await response.json(); if (!response.ok) throw new Error(result.error || "Không xem trước được thay đổi"); setPreview(result);
    } catch (error) { onError(error instanceof Error ? error.message : "Không xem trước được thay đổi"); }
  }
  async function applyPreview() {
    const token = preview?.preview_token;
    if (typeof token !== "string") return;
    if (!window.confirm("Áp dụng các trường đã xem trước cho sản phẩm?")) return;
    try {
      const response = await apiFetch("/api/dashboard/product-change/apply", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ preview_token: token }) });
      const result = await response.json(); if (!response.ok) throw new Error(result.error || "Không lưu được thay đổi"); onError("Đã cập nhật sản phẩm."); setPreview(null); onDirtyChange(false);
    } catch (error) { onError(error instanceof Error ? error.message : "Không lưu được thay đổi"); }
  }
  return <>
    <form onSubmit={requestPreview} onChange={() => onDirtyChange(true)} style={{ display: "grid", gap: 10, borderTop: "1px solid var(--line)", marginTop: 22, paddingTop: 18 }}>
      <h3 style={{ margin: 0, fontSize: 14 }}>Chỉnh sửa sản phẩm</h3>
      <label htmlFor="product-name">Tên sản phẩm</label><input id="product-name" className="search-input" value={name} onChange={(e) => setName(e.target.value)} />
      <label htmlFor="product-sku">SKU</label><input id="product-sku" className="search-input" value={sku} onChange={(e) => setSku(e.target.value)} />
      <label htmlFor="product-price">Giá niêm yết</label><input id="product-price" className="search-input" inputMode="decimal" value={regularPrice} onChange={(e) => setRegularPrice(e.target.value)} />
      <label htmlFor="product-sale-price">Giá khuyến mãi</label><input id="product-sale-price" className="search-input" inputMode="decimal" value={salePrice} onChange={(e) => setSalePrice(e.target.value)} />
      <label htmlFor="product-description">Mô tả</label><textarea id="product-description" className="search-input" style={{ minHeight: 100, padding: 10 }} value={description} onChange={(e) => setDescription(e.target.value)} />
      <label htmlFor="product-short-description">Mô tả ngắn</label><textarea id="product-short-description" className="search-input" style={{ minHeight: 80, padding: 10 }} value={shortDescription} onChange={(e) => setShortDescription(e.target.value)} />
      <label htmlFor="product-stock">Số lượng tồn</label><input id="product-stock" className="search-input" inputMode="numeric" value={stockQuantity} onChange={(e) => setStockQuantity(e.target.value)} />
      <label><input type="checkbox" checked={manageStock} onChange={(e) => setManageStock(e.target.checked)} /> Quản lý số lượng tồn</label>
      <label htmlFor="product-stock-status">Trạng thái kho</label><select id="product-stock-status" className="search-input" value={stockStatus} onChange={(e) => setStockStatus(e.target.value)}><option value="instock">Còn hàng</option><option value="outofstock">Hết hàng</option><option value="onbackorder">Đặt trước</option></select>
      <ProductListEditor title="Danh mục" rows={categories} setRows={setCategories} fields={[{ key: "id", label: "Mã danh mục", kind: "number" }, { key: "name", label: "Tên danh mục" }]} />
      <ProductListEditor title="Thẻ sản phẩm" rows={tags} setRows={setTags} fields={[{ key: "id", label: "Mã thẻ", kind: "number" }, { key: "name", label: "Tên thẻ" }]} />
      <ProductListEditor title="Hình ảnh sản phẩm" rows={images} setRows={setImages} fields={[{ key: "id", label: "Mã ảnh", kind: "number" }, { key: "src", label: "URL ảnh" }, { key: "alt", label: "Mô tả ảnh" }]} />
      <ProductListEditor title="Thuộc tính" rows={attributes} setRows={setAttributes} fields={[{ key: "name", label: "Tên thuộc tính" }, { key: "options", label: "Giá trị (phân tách bằng dấu phẩy)", kind: "list" }]} />
      <fieldset style={{ border: "1px solid var(--line)", borderRadius: 7, padding: 12 }}><legend>Giá bán sỉ</legend><label><input type="checkbox" checked={wholesaleIsEnabled} onChange={(e) => setWholesaleIsEnabled(e.target.checked)} /> Cho phép bán sỉ</label><label htmlFor="product-wholesale-price" style={{ display: "block", marginTop: 8 }}>Giá cơ sở bán sỉ</label><input id="product-wholesale-price" className="search-input" inputMode="decimal" value={wholesalePriceValue} onChange={(e) => setWholesalePriceValue(e.target.value)} /></fieldset>
      <fieldset className="translation-editor"><legend>Nội dung bảy ngôn ngữ</legend><div className="locale-tabs" role="group" aria-label="Chọn ngôn ngữ">{[["vi", "Tiếng Việt"], ["en", "English"], ["ru", "Русский"], ["hi", "हिन्दी"], ["zh", "中文"], ["ko", "한국어"], ["ja", "日本語"]].map(([locale, label]) => <button type="button" key={locale} aria-pressed={translationLocale === locale} className={`locale-tab${translationLocale === locale ? " is-active" : ""}`} onClick={() => setTranslationLocale(locale)}>{label}</button>)}</div>{[["name", "Tên sản phẩm"], ["excerpt", "Mô tả ngắn"], ["description", "Mô tả"], ["notes", "Ghi chú hương vị"]].map(([field, label]) => <label className="translation-field" key={field}>{label}{field === "description" || field === "notes" ? <textarea className="search-input" value={String(translations[translationLocale]?.[field] ?? "")} onChange={(event) => setTranslations((current) => setProductTranslation(current, translationLocale, field, event.target.value))} /> : <input className="search-input" value={String(translations[translationLocale]?.[field] ?? "")} onChange={(event) => setTranslations((current) => setProductTranslation(current, translationLocale, field, event.target.value))} />}</label>)}</fieldset>
      <small className="muted">Mọi thay đổi đều được xem trước trước khi lưu. Thay đổi mô tả cần nội dung cho vi, en, ru, hi, zh, ko và ja.</small>
      <button className="quiet-button" disabled={busy} type="submit">Xem trước thay đổi</button>
      {preview && <section className="preview-panel" aria-label="Xem trước thay đổi"><h4>Thay đổi cần duyệt</h4>{Array.isArray(preview.warnings) && preview.warnings.map((warning, index) => <p className="warning-box" role="note" key={index}>{String(warning)}</p>)}{Array.isArray(preview.diff) && preview.diff.length > 0 ? <div className="preview-diff">{(preview.diff as RecordRow[]).map((change, index) => <div className="preview-change" key={`${String(change.field)}-${index}`}><strong>{String(change.field)}</strong><div><small>Trước</small><span>{previewValue(change.before)}</span></div><div><small>Sau</small><span>{previewValue(change.after)}</span></div></div>)}</div> : <p className="muted">Chưa có trường nào thay đổi.</p>}<button className="action-button" disabled={busy || typeof preview.preview_token !== "string" || !Array.isArray(preview.diff) || preview.diff.length === 0} type="button" onClick={() => void applyPreview()}>Áp dụng thay đổi đã xem</button></section>}
    </form>
    {String(product.type) === "variable" && <VariationManager productId={Number(product.id)} onNotice={onError} />}
  </>;
}

type ProductField = { key: string; label: string; kind?: "number" | "list" };
function ProductListEditor({ title, rows, setRows, fields }: { title: string; rows: RecordRow[]; setRows: (rows: RecordRow[]) => void; fields: ProductField[] }) {
  return <fieldset className="product-list-editor"><legend>{title}</legend>{rows.map((row, index) => <div className="product-repeat-row" key={`${title}-${index}`}>{fields.map((field) => <label key={field.key}>{field.label}{field.kind === "list" ? <textarea className="search-input" value={Array.isArray(row[field.key]) ? (row[field.key] as string[]).join(", ") : String(row[field.key] ?? "")} onChange={(event) => setRows(updateProductRow(rows, index, field.key, event.target.value, field.kind))} /> : <input className="search-input" inputMode={field.kind === "number" ? "numeric" : undefined} value={String(row[field.key] ?? "")} onChange={(event) => setRows(updateProductRow(rows, index, field.key, event.target.value, field.kind))} />}</label>)}<button type="button" className="quiet-button" aria-label={`Xóa ${title} ${index + 1}`} onClick={() => setRows(rows.filter((_, position) => position !== index))}>Xóa</button></div>)}<button type="button" className="quiet-button repeat-add" onClick={() => setRows(appendProductRow(rows, fields))}>+ Thêm mục</button></fieldset>;
}

function previewValue(value: unknown) { if (value == null || value === "") return "—"; if (typeof value === "object") return JSON.stringify(value); return String(value); }

function VariationManager({ productId, onNotice }: { productId: number; onNotice: (message: string) => void }) {
  const [items, setItems] = useState<RecordRow[]>([]);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const load = useCallback(async () => {
    setBusy(true); setError("");
    try { const response = await apiFetch(`/api/dashboard/products/${productId}/variations?per_page=100`); const result = await response.json(); if (!response.ok) throw new Error(result.error || "Không tải được biến thể"); setItems(result.items ?? []); }
    catch (e) { setError(e instanceof Error ? e.message : "Không tải được biến thể"); }
    finally { setBusy(false); }
  }, [productId]);
  useEffect(() => { const timer = window.setTimeout(() => { void load(); }, 0); return () => window.clearTimeout(timer); }, [load]);
  return <section style={{ borderTop: "1px solid var(--line)", marginTop: 22, paddingTop: 18 }}><h3 style={{ fontSize: 14 }}>Biến thể ({items.length})</h3>{error && <div className="error-box" role="alert">{error}</div>}{busy && <p className="muted">Đang tải biến thể…</p>}{items.map((item) => <VariationEditor key={String(item.id)} productId={productId} variation={item} onSaved={() => { onNotice("Đã cập nhật biến thể."); void load(); }} />)}{!busy && !error && items.length === 0 && <p className="muted">Sản phẩm chưa có biến thể.</p>}</section>;
}

function VariationEditor({ productId, variation, onSaved }: { productId: number; variation: RecordRow; onSaved: () => void }) {
  const [open, setOpen] = useState(false);
  const [regularPrice, setRegularPrice] = useState(String(variation.regular_price ?? ""));
  const [salePrice, setSalePrice] = useState(String(variation.sale_price ?? ""));
  const [stockQuantity, setStockQuantity] = useState(String(variation.stock_quantity ?? ""));
  const [stockStatus, setStockStatus] = useState(String(variation.stock_status ?? "instock"));
  const [manageStock, setManageStock] = useState(Boolean(variation.manage_stock));
  const [preview, setPreview] = useState<Record<string, unknown> | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  async function requestPreview() {
    setBusy(true); setError("");
    try {
      const changes: Record<string, unknown> = {};
      if (regularPrice !== String(variation.regular_price ?? "")) changes.regular_price = regularPrice;
      if (salePrice !== String(variation.sale_price ?? "")) changes.sale_price = salePrice;
      if (stockQuantity !== String(variation.stock_quantity ?? "")) changes.stock_quantity = stockQuantity === "" ? null : Number(stockQuantity);
      if (stockStatus !== String(variation.stock_status ?? "instock")) changes.stock_status = stockStatus;
      if (manageStock !== Boolean(variation.manage_stock)) changes.manage_stock = manageStock;
      const response = await apiFetch("/api/dashboard/product-change/preview", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ action: "update", resource_type: "variation", product_id: productId, id: Number(variation.id), changes, translations: {} }) });
      const result = await response.json(); if (!response.ok) throw new Error(result.error || "Không xem trước được biến thể"); setPreview(result);
    } catch (e) { setError(e instanceof Error ? e.message : "Không xem trước được biến thể"); }
    finally { setBusy(false); }
  }
  async function applyPreview() {
    if (typeof preview?.preview_token !== "string" || !window.confirm("Áp dụng thay đổi giá và kho cho biến thể này?")) return;
    setBusy(true); setError("");
    try { const response = await apiFetch("/api/dashboard/product-change/apply", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ preview_token: preview.preview_token }) }); const result = await response.json(); if (!response.ok) throw new Error(result.error || "Không lưu được biến thể"); setPreview(null); onSaved(); }
    catch (e) { setError(e instanceof Error ? e.message : "Không lưu được biến thể"); }
    finally { setBusy(false); }
  }

  return <div className="card variation-card"><div className="toolbar"><span><strong>{String(variation.name || `Biến thể #${variation.id}`)}</strong><small className="muted" style={{ display: "block" }}>SKU {String(variation.sku || "—")} · {JSON.stringify(variation.attributes ?? [])}</small></span><button className="quiet-button" type="button" onClick={() => setOpen((value) => !value)}>{open ? "Đóng" : "Chỉnh sửa"}</button></div>{open && <div style={{ display: "grid", gap: 9, padding: 14 }}><label htmlFor={`variation-price-${variation.id}`}>Giá niêm yết</label><input id={`variation-price-${variation.id}`} className="search-input" inputMode="decimal" value={regularPrice} onChange={(e) => setRegularPrice(e.target.value)} /><label htmlFor={`variation-sale-${variation.id}`}>Giá khuyến mãi</label><input id={`variation-sale-${variation.id}`} className="search-input" inputMode="decimal" value={salePrice} onChange={(e) => setSalePrice(e.target.value)} /><label htmlFor={`variation-stock-${variation.id}`}>Tồn kho</label><input id={`variation-stock-${variation.id}`} className="search-input" inputMode="numeric" value={stockQuantity} onChange={(e) => setStockQuantity(e.target.value)} /><label><input type="checkbox" checked={manageStock} onChange={(e) => setManageStock(e.target.checked)} /> Quản lý số lượng tồn</label><label htmlFor={`variation-stock-status-${variation.id}`}>Trạng thái kho</label><select id={`variation-stock-status-${variation.id}`} className="search-input" value={stockStatus} onChange={(e) => setStockStatus(e.target.value)}><option value="instock">Còn hàng</option><option value="outofstock">Hết hàng</option><option value="onbackorder">Đặt trước</option></select>{error && <div className="error-box" role="alert">{error}</div>}<button className="quiet-button" type="button" disabled={busy} onClick={() => void requestPreview()}>Xem trước biến thể</button>{preview && <><pre style={{ whiteSpace: "pre-wrap", overflowWrap: "anywhere" }}>{JSON.stringify(preview, null, 2)}</pre><button className="action-button" type="button" disabled={busy} onClick={() => void applyPreview()}>Áp dụng thay đổi</button></>}</div>}</div>;
}

function ContentEditor({ record, busy, onSave, onError, onDirtyChange }: { record: RecordRow; busy: boolean; onSave: (changes: Record<string, unknown>) => Promise<{ ok: boolean }>; onError: (message: string) => void; onDirtyChange: (dirty: boolean) => void }) {
  const [title, setTitle] = useState(String(record.title ?? ""));
  const [excerpt, setExcerpt] = useState(String(record.excerpt ?? ""));
  const [content, setContent] = useState(String(record.content ?? ""));
  const [status, setStatus] = useState(String(record.status ?? "draft"));
  const [preview, setPreview] = useState(false);
  async function save(nextStatus = status) {
    if (nextStatus === "publish" && !window.confirm("Đã xem trước nội dung và muốn xuất bản bài viết này?")) return;
    onError("");
    if ((await onSave({ title, excerpt, content, status: nextStatus, expected_revision: record.revision, ...(nextStatus === "publish" ? { confirm_publish: true } : {}) })).ok) onDirtyChange(false);
  }
  return <div onChange={() => onDirtyChange(true)} style={{ display: "grid", gap: 10, marginTop: 22, borderTop: "1px solid var(--line)", paddingTop: 18 }}>
    <h3 style={{ margin: 0, fontSize: 14 }}>Chỉnh sửa bài viết</h3>
    <label htmlFor="content-title">Tiêu đề</label><input id="content-title" className="search-input" value={title} onChange={(e) => setTitle(e.target.value)} />
    <label htmlFor="content-excerpt">Mô tả ngắn</label><textarea id="content-excerpt" className="search-input" style={{ height: 75, padding: 10 }} value={excerpt} onChange={(e) => setExcerpt(e.target.value)} />
    <label htmlFor="content-body">Nội dung HTML</label><textarea id="content-body" className="search-input" style={{ height: 210, padding: 10, fontFamily: "monospace", fontSize: 11 }} value={content} onChange={(e) => setContent(e.target.value)} />
    <label htmlFor="content-status">Trạng thái</label><select id="content-status" className="search-input" value={status} onChange={(e) => setStatus(e.target.value)}><option value="draft">Bản nháp</option><option value="pending">Chờ duyệt</option><option value="private">Riêng tư</option><option value="publish">Công khai</option></select>
    <div style={{ display: "flex", gap: 8 }}><button className="quiet-button" type="button" onClick={() => setPreview((value) => !value)}>{preview ? "Ẩn xem trước" : "Xem trước nội dung"}</button><button className="action-button" type="button" disabled={busy} onClick={() => void save()}>{status === "publish" ? "Xác nhận xuất bản" : "Lưu thay đổi"}</button></div>
    {preview && <article className="card" style={{ padding: 16 }}><strong>{title || "Chưa có tiêu đề"}</strong><p className="muted" style={{ whiteSpace: "pre-wrap", lineHeight: 1.6 }}>{content.replace(/<[^>]*>/g, " ").replace(/\s+/g, " ").trim().slice(0, 1600)}</p></article>}
  </div>;
}

const descriptions: Record<string, string> = {
  orders: "Kiểm tra thanh toán, thông tin giao hàng và cập nhật xử lý đơn.", shipments: "Rà soát đơn trước khi đặt, theo dõi và xử lý vận đơn ViettelPost.", products: "Danh mục sản phẩm và tình trạng kho hiện tại từ WooCommerce.", customers: "Hồ sơ khách hàng và lịch sử mua sắm.", leads: "Yêu cầu liên hệ, đăng ký nhận mẫu và yêu cầu bán sỉ.", "wholesale-orders": "Đơn đặt hàng riêng của khách bán sỉ.", content: "Bài viết và trang đang lưu trong WordPress.", marketing: "Người đăng ký bản tin và chiến dịch gửi thư.", reviews: "Đánh giá đang chờ xử lý và đã được duyệt.", finance: "Giá vốn sản phẩm và sổ theo dõi phân phối.", settings: "Trạng thái kết nối và cấu hình vận hành được hỗ trợ.",
};

function formatCell(key: string, value: unknown) {
  if (value == null || value === "") return <span className="muted">—</span>;
  if (key === "source") return sourceLabels[String(value)] ?? String(value);
  if (key === "fulfillment") return String(value) === "self" ? "Nhân viên giao trực tiếp" : "Giao qua đối tác (ViettelPost)";
  if (key === "status" || key === "email_status") return <span className="status" data-status={String(value)}>{statusLabels[String(value)] || String(value).replaceAll("-", " ")}</span>;
  if (["billing", "shipping"].includes(key) && typeof value === "object") { const address = value as Record<string, unknown>; return [address.first_name, address.last_name, address.company, address.address_1, address.address_2, address.city, address.state, address.postcode, address.country].filter((part) => typeof part === "string" && part.trim()).join(", ") || "Chưa có địa chỉ"; }
  if (typeof value === "object") return <span className="muted">{Array.isArray(value) ? `${value.length} mục` : "Chi tiết"}</span>;
  if (["total", "price", "spent"].includes(key) && Number.isFinite(Number(value))) return Number(value).toLocaleString("vi-VN");
  if (["date", "paid_at", "modified", "created_at"].includes(key)) { const date = new Date(String(value)); if (!Number.isNaN(date.getTime())) return new Intl.DateTimeFormat("vi-VN", { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Ho_Chi_Minh" }).format(date); }
  return String(value);
}

function formatMoney(value: unknown, currency: unknown) {
  const numeric = Number(value);
  if (value == null || value === "" || !Number.isFinite(numeric)) return <span className="muted">—</span>;
  const code = typeof currency === "string" && /^[A-Z]{3}$/.test(currency) ? currency : "VND";
  try { return new Intl.NumberFormat("vi-VN", { style: "currency", currency: code, maximumFractionDigits: code === "VND" ? 0 : 2 }).format(numeric); }
  catch { return `${numeric.toLocaleString("vi-VN")} ${code}`; }
}

/** Opening phrases of notes WooCommerce or the courier writes by themselves. */
const automatedNotePrefixes = [
  "Order status changed",
  "Email \"",
  "Note added by ",
  "Stock levels reduced",
  "Stock levels restored",
  "Payment complete",
  "Order pending payment",
  "ViettelPost",
];

/** The newest note a person wrote on the order, preferring internal notes. */
function latestOrderNote(detail: RecordRow | null): string {
  const notes = (Array.isArray(detail?.notes) ? detail.notes as RecordRow[] : [])
    .slice()
    .sort((a, b) => Number(b.id ?? 0) - Number(a.id ?? 0));
  const usable = notes.filter((note) => {
    const content = String(note.content ?? "").trim();
    if (!content) return false;
    // WooCommerce returns the author's name for hand-written notes and
    // "system" for automation. Older dashboard notes were saved as automation,
    // so fall back to matching the opening phrases of generated notes.
    if (typeof note.added_by === "string" && note.added_by && note.added_by !== "system") return true;
    return !automatedNotePrefixes.some((prefix) => content.startsWith(prefix));
  });
  const chosen = usable.find((note) => !note.customer_note) ?? usable[0];
  return chosen ? String(chosen.content ?? "").trim() : "";
}

/** Order notes expose `date_created` as `{ date, timezone, timezone_type }`. */
function noteTimestamp(value: unknown): string {
  if (value && typeof value === "object" && !Array.isArray(value)) {
    const date = (value as RecordRow).date;
    if (typeof date === "string") return date;
  }
  return typeof value === "string" ? value : "";
}

function normalizeOrderDetail(value: unknown): RecordRow {
  if (!value || typeof value !== "object" || Array.isArray(value)) return {};
  const detail = value as RecordRow;
  const items = detail.items;
  if (Array.isArray(items)) return detail;
  if (items && typeof items === "object") {
    return { ...detail, items: Object.values(items).filter((item): item is RecordRow => Boolean(item) && typeof item === "object" && !Array.isArray(item)) };
  }
  return detail;
}

function formatDetailValue(key: string, value: unknown, record: RecordRow) {
  if (key === "tracking") {
    if (typeof value !== "string" || !value) return <span className="muted">—</span>;
    return <span className="cell-with-copy" style={{ display: "inline-flex", alignItems: "center", gap: 6 }}>{value}<CopyValueButton value={value} /></span>;
  }
  if (key === "tracking_url") {
    if (typeof value !== "string" || !value) return <span className="muted">—</span>;
    return <a href={value} target="_blank" rel="noreferrer">Mở trang theo dõi ViettelPost ↗</a>;
  }
  if (key === "is_cod") return value ? "Có — thu tiền khi giao" : "Không — đã trả trước";
  if (["total", "cod_amount", "courier_fee", "quoted_shipping"].includes(key)) {
    if (value == null || value === "") return <span className="muted">—</span>;
    return formatMoney(value, record.currency);
  }
  return formatCell(key, value);
}

const shipmentStages: { key: string; label: string; codes: string[] }[] = [
  { key: "created", label: "Tạo đơn hàng", codes: ["", "102"] },
  { key: "pending", label: "Đã nhận hàng", codes: ["103", "104", "105", "200", "202"] },
  { key: "delivering", label: "Đang vận chuyển", codes: ["300", "400", "500", "508", "509", "550"] },
  { key: "done", label: "Phát thành công", codes: ["501"] },
  { key: "cancelled", label: "Đã hủy", codes: ["101", "107", "201", "503"] },
  { key: "issue", label: "Phát thất bại", codes: ["506", "507"] },
  { key: "returning", label: "Đang chuyển hoàn", codes: ["502", "505", "515"] },
  { key: "returned", label: "Đã chuyển hoàn", codes: ["504"] },
];

function shipmentStageFor(status: string) {
  const code = String(status || "");
  return shipmentStages.find((stage) => stage.codes.includes(code)) ?? { key: "other", label: "Cập nhật khác", codes: [] };
}

function ShipmentStatusBadge({ code }: { code: string }) {
  if (!code) return <span className="muted">—</span>;
  const stage = shipmentStageFor(code);
  return <span className="status" data-status={stage.key}>{stage.label}</span>;
}

function formatJourneyDate(value: string) {
  const match = String(value || "").match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}:\d{2}:\d{2})/);
  return match ? `${match[3]}/${match[2]}/${match[1]} ${match[4]}` : String(value || "");
}

const shipmentPaymentLabels: Record<string, string> = {
  "1": "Không thu hộ",
  "2": "Thu hộ tiền hàng + cước",
  "3": "Thu hộ tiền hàng (cước trừ vào COD)",
  "4": "Thu hộ tiền cước",
};

function formatWeight(value: unknown) {
  const grams = Number(value);
  if (!Number.isFinite(grams) || grams <= 0) return "";
  return grams >= 1000 ? `${(grams / 1000).toLocaleString("vi-VN", { maximumFractionDigits: 2 })} kg` : `${grams.toLocaleString("vi-VN")} g`;
}

/** Itemized ViettelPost shipping charge (adapter `cost_breakdown`). */
function ShipmentCostBreakdown({ breakdown, currency }: { breakdown: RecordRow; currency: string }) {
  const money = (key: string) => Number(breakdown[key] ?? 0);
  const has = (key: string) => breakdown[key] != null && breakdown[key] !== "";
  const total = money("total");
  const codAmount = money("cod_amount");
  const paymentType = String(breakdown.payment_type ?? "");
  const weight = formatWeight(breakdown.weight_g);
  const feeRows: { label: string; value: number }[] = [{ label: "Cước chính", value: money("main_fee") }];
  if (has("fuel_surcharge") && money("fuel_surcharge") !== 0) feeRows.push({ label: "Phụ phí xăng dầu (PXD)", value: money("fuel_surcharge") });
  if (has("cod_fee") && money("cod_fee") !== 0) feeRows.push({ label: "Phí thu hộ (COD)", value: money("cod_fee") });
  if (has("vat") && money("vat") !== 0) feeRows.push({ label: "Thuế GTGT (VAT)", value: money("vat") });
  return <section className="detail-section"><h3>Chi tiết cước phí (ViettelPost)</h3>
    {feeRows.map((row) => <div className="alert-row" key={row.label}><span className="muted">{row.label}</span><strong>{formatMoney(row.value, currency)}</strong></div>)}
    <div className="alert-row" style={{ borderTop: "1px solid var(--line-strong)", fontWeight: 700 }}><span className="muted">Tổng cước</span><strong>{formatMoney(total, currency)}</strong></div>
    {has("cod_amount") ? <div className="alert-row"><span className="muted">Tiền thu hộ (COD)</span><strong>{formatMoney(codAmount, currency)}</strong></div> : null}
    {has("cod_amount") && paymentType === "3" ? <div className="alert-row"><span className="muted">Thực nhận (COD − tổng cước)</span><strong>{formatMoney(codAmount - total, currency)}</strong></div> : null}
    {paymentType ? <div className="alert-row"><span className="muted">Hình thức thu</span><strong>{shipmentPaymentLabels[paymentType] ?? `Loại ${paymentType}`}</strong></div> : null}
    {weight ? <div className="alert-row"><span className="muted">Khối lượng</span><strong>{weight}</strong></div> : null}
    {has("service") ? <div className="alert-row"><span className="muted">Dịch vụ</span><strong>{String(breakdown.service)}</strong></div> : null}
    {has("last_courier") ? <div className="alert-row"><span className="muted">Nhân viên phát</span><strong>{String(breakdown.last_courier)}{has("last_courier_phone") ? ` · ${String(breakdown.last_courier_phone)}` : ""}</strong></div> : null}
    {has("updated_at") ? <div className="alert-row"><span className="muted">Cập nhật cước lúc</span><strong>{formatCell("date", breakdown.updated_at)}</strong></div> : null}
  </section>;
}

function ShipmentTimeline({ history }: { history: RecordRow[] }) {
  const [collapsed, setCollapsed] = useState<Record<string, boolean>>({});
  const groups: { key: string; label: string; events: RecordRow[] }[] = [];
  for (const event of history) {
    const stage = shipmentStageFor(String(event.status ?? ""));
    let group = groups.find((item) => item.key === stage.key);
    if (!group) { group = { key: stage.key, label: stage.label, events: [] }; groups.push(group); }
    group.events.push(event);
  }
  return <section className="detail-section"><h3>Hành trình đơn</h3>
    {groups.length === 0
      ? <p className="muted">Chưa có dữ liệu hành trình. Hành trình sẽ hiển thị khi ViettelPost gửi cập nhật trạng thái.</p>
      : <ol className="shipment-journey">{groups.map((group) => {
        const isOpen = !collapsed[group.key];
        return <li className="journey-group" key={group.key}>
          <button type="button" className="journey-group-head" aria-expanded={isOpen} onClick={() => setCollapsed((current) => ({ ...current, [group.key]: isOpen }))}>
            <span className="journey-group-label">{group.label}</span>
            <ChevronDown className={`journey-chevron${isOpen ? " is-open" : ""}`} size={16} aria-hidden="true" />
          </button>
          {isOpen && <ol className="journey-events">{group.events.map((event, index) => <li className="journey-event" key={`${String(event.status)}-${String(event.date)}-${index}`}>
            <span className="journey-text">{formatJourneyDate(String(event.date ?? ""))}{event.note ? `: ${String(event.note)}` : ""}</span>
          </li>)}</ol>}
        </li>;
      })}</ol>}
  </section>;
}

function CopyValueButton({ value }: { value: string }) {
  const [copied, setCopied] = useState(false);
  async function copy() {
    try { await navigator.clipboard.writeText(value); }
    catch {
      const area = document.createElement("textarea");
      area.value = value; area.setAttribute("readonly", ""); area.style.position = "fixed"; area.style.opacity = "0";
      document.body.appendChild(area); area.select();
      try { document.execCommand("copy"); } finally { document.body.removeChild(area); }
    }
    setCopied(true);
    window.setTimeout(() => setCopied(false), 1500);
  }
  return <button type="button" className="copy-value-button" onClick={() => void copy()} aria-label={`Sao chép ${value}`} title="Sao chép" style={{ display: "inline-flex", alignItems: "center", justifyContent: "center", padding: 2, border: "none", borderRadius: 4, background: "transparent", color: copied ? "var(--accent, #3b8c4a)" : "var(--muted, #777)", cursor: "pointer", lineHeight: 0 }}>{copied ? <Check size={13} aria-hidden="true" /> : <Copy size={13} aria-hidden="true" />}</button>;
}

