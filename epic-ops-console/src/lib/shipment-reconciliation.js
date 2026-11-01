const outcomes = {
  book: ["booked", "not_booked"],
  cancel: ["cancelled", "not_cancelled"],
};

export function reconciliationOutcomes(action) {
  return outcomes[action] ?? [];
}

export function reconciliationRequest(action, outcome, tracking) {
  if (!reconciliationOutcomes(action).includes(outcome)) {
    throw new Error("Unsupported shipment reconciliation");
  }
  if (action === "book" && outcome === "booked") {
    const normalized = typeof tracking === "string" ? tracking.trim().toUpperCase() : "";
    if (!/^[A-Za-z0-9-]{4,50}$/.test(normalized)) {
      throw new Error("Enter the ViettelPost tracking code");
    }
    return { action: "reconcile", outcome, tracking: normalized };
  }
  return { action: "reconcile", outcome };
}
