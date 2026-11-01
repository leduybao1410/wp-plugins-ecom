export function updateProductRow(rows, index, key, value, kind = "text") {
  return rows.map((row, position) => {
    if (position !== index) return row;
    const next = { ...row };
    if (kind === "number") {
      if (value === "") delete next[key];
      else next[key] = Number(value);
    } else if (kind === "list") next[key] = value.split(",").map((item) => item.trim()).filter(Boolean);
    else next[key] = value;
    return next;
  });
}

export function appendProductRow(rows, fields) {
  return [...rows, Object.fromEntries(fields.map((field) => [field.key, field.kind === "list" ? [] : ""]))];
}

export function setProductTranslation(translations, locale, field, value) {
  return { ...translations, [locale]: { ...(translations[locale] ?? {}), [field]: value } };
}
