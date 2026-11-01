// ViettelPost digitalized label print options. The adapter owns the tokenized
// print URL and exposes the saved plugin defaults as `label_options` on the
// shipment detail; these helpers keep the per-print choice pure and testable.

export const LABEL_SIZES = [
  { value: "1", label: "A5" },
  { value: "2", label: "A6" },
  { value: "100", label: "A7" },
];

const LABEL_SIZE_VALUES = LABEL_SIZES.map((size) => size.value);
const FALLBACK_OPTIONS = { sizes: { "1": "A5", "2": "A6", "100": "A7" }, defaultSize: "1", defaultShowPostage: true };

export function labelOptions(record) {
  const options = record && typeof record === "object" && record.label_options && typeof record.label_options === "object"
    ? record.label_options
    : {};
  const sizes = options.sizes && typeof options.sizes === "object" && Object.keys(options.sizes).length
    ? options.sizes
    : FALLBACK_OPTIONS.sizes;
  const defaultSize = LABEL_SIZE_VALUES.includes(String(options.default_size))
    ? String(options.default_size)
    : FALLBACK_OPTIONS.defaultSize;
  const defaultShowPostage = typeof options.default_show_postage === "boolean"
    ? options.default_show_postage
    : FALLBACK_OPTIONS.defaultShowPostage;
  return { sizes, defaultSize, defaultShowPostage };
}

export function labelPrintRequest({ size, showPostage } = {}) {
  const value = size == null || size === "" ? LABEL_SIZE_VALUES[0] : String(size);
  if (!LABEL_SIZE_VALUES.includes(value)) {
    throw new Error("Choose a supported label size (A5, A6 or A7)");
  }
  return { action: "label", label_size: value, show_postage: showPostage === true };
}
