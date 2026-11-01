"use client";

import { useState } from "react";
import { linkShipmentRequest, normalizeViettelPostWaybill } from "@/lib/shipment-link";

type RecordRow = Record<string, unknown>;

function formatMoney(value: unknown, currency: unknown) {
  const amount = Number(value ?? 0);
  return `${amount.toLocaleString("vi-VN")} ${String(currency || "VND")}`;
}

export function ExistingShipmentLink({ record, busy, open, onOpenChange, onError, onSubmit }: {
  record: RecordRow;
  busy: boolean;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onError: (message: string) => void;
  onSubmit: (body: Record<string, unknown>) => Promise<void>;
}) {
  const [tracking, setTracking] = useState("");
  const [status, setStatus] = useState("");
  const [confirmedMatch, setConfirmedMatch] = useState(false);
  const [trackingError, setTrackingError] = useState("");
  const billing = (record.billing && typeof record.billing === "object" ? record.billing : {}) as RecordRow;
  const shipping = (record.shipping && typeof record.shipping === "object" ? record.shipping : {}) as RecordRow;
  const address = Object.keys(shipping).some((key) => Boolean(shipping[key])) ? shipping : billing;
  const recipient = String(record.recipient_name || `${shipping.first_name || billing.first_name || ""} ${shipping.last_name || billing.last_name || ""}`.trim() || record.customer || "—");
  const phone = String(record.recipient_phone || shipping.phone || billing.phone || "—");
  const addressText = [address.address_1, address.address_2, address.city, address.state, address.postcode].filter(Boolean).join(", ");
  const payment = String(record.payment_method || "");
  const isCod = typeof record.is_cod === "boolean" ? record.is_cod : /cod|cash on delivery|thanh toán khi nhận hàng/i.test(payment);
  const statusOptions = (record.status_options || record.shipment_status_options || {}) as Record<string, string>;
  const orderNumber = String(record.number || `#${String(record.id || "")}`);

  if (!open) return <button type="button" className="quiet-button" disabled={busy} onClick={() => { setTracking(""); setStatus(""); setConfirmedMatch(false); setTrackingError(""); onError(""); onOpenChange(true); }}>Liên kết vận đơn có sẵn</button>;

  return <form className="detail-section" onSubmit={(event) => {
    event.preventDefault();
    try {
      const body = linkShipmentRequest({ tracking, confirmedMatch, status });
      setTrackingError("");
      void onSubmit(body);
    } catch (error) {
      const message = error instanceof Error ? error.message : "Kiểm tra lại thông tin vận đơn.";
      if (/waybill/i.test(message)) setTrackingError(message);
      else onError(message);
    }
  }}>
    <h3>Liên kết vận đơn có sẵn</h3>
    <p className="muted">Nhập mã vận đơn đã được ViettelPost tạo. Thao tác này chỉ liên kết vào đơn hàng, không tạo yêu cầu vận chuyển mới.</p>
    <div className="alert-row"><span className="muted">Đơn hàng</span><strong>{orderNumber}</strong></div>
    <div className="alert-row"><span className="muted">Người nhận</span><strong>{recipient} · {phone}</strong></div>
    {addressText && <div className="alert-row"><span className="muted">Địa chỉ giao</span><strong>{addressText}</strong></div>}
    <div className="alert-row"><span className="muted">COD theo WooCommerce</span><strong>{isCod ? formatMoney(record.total, record.currency) : "Không thu COD"}</strong></div>
    <label htmlFor="existing-shipment-tracking">Mã vận đơn ViettelPost</label>
    <input id="existing-shipment-tracking" className="search-input" value={tracking} autoComplete="off" aria-invalid={Boolean(trackingError)} aria-describedby={trackingError ? "existing-shipment-tracking-error" : undefined} onChange={(event) => { setTracking(event.target.value); if (trackingError) setTrackingError(""); }} onBlur={() => {
      if (!tracking.trim()) return;
      try { normalizeViettelPostWaybill(tracking); setTrackingError(""); }
      catch (error) { setTrackingError(error instanceof Error ? error.message : "Enter a valid ViettelPost waybill"); }
    }} />
    {trackingError && <p id="existing-shipment-tracking-error" className="error-box" role="alert">{trackingError}</p>}
    <label htmlFor="existing-shipment-status">Trạng thái ViettelPost (không bắt buộc)</label>
    <select id="existing-shipment-status" className="search-input" value={status} onChange={(event) => setStatus(event.target.value)}>
      <option value="">Chưa xác nhận trạng thái</option>
      {Object.entries(statusOptions).map(([code, label]) => <option key={code} value={code}>{code} · {label}</option>)}
    </select>
    <label style={{ display: "flex", alignItems: "flex-start", gap: 8 }}>
      <input type="checkbox" checked={confirmedMatch} onChange={(event) => setConfirmedMatch(event.target.checked)} />
      <span>Tôi đã kiểm tra mã đơn, người nhận, địa chỉ và COD trên ViettelPost.</span>
    </label>
    <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
      <button className="action-button" type="submit" disabled={busy}>{busy ? "Đang liên kết…" : "Liên kết vận đơn"}</button>
      <button className="quiet-button" type="button" disabled={busy} onClick={() => onOpenChange(false)}>Đóng</button>
    </div>
  </form>;
}
