export function linkShipmentRequest({ tracking, confirmedMatch, status }) {
  const normalizedTracking = normalizeViettelPostWaybill(tracking);
  if (confirmedMatch !== true) {
    throw new Error("Confirm that the ViettelPost shipment matches this order");
  }
  const body = { action: "link", tracking: normalizedTracking, confirmed_match: true };
  if (typeof status === "string" && status.trim()) body.status = status.trim();
  return body;
}

export function normalizeViettelPostWaybill(tracking) {
  const normalizedTracking = typeof tracking === "string" ? tracking.trim().toUpperCase() : "";
  if (!/^[A-Z0-9-]{4,50}$/.test(normalizedTracking)) throw new Error("Enter a valid ViettelPost waybill");
  return normalizedTracking;
}
