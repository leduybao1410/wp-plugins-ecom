export const THEME_STORAGE_KEY = "epic-admin-theme";
const themes = new Set(["system", "light", "dark"]);

export function getThemePreference(storage) {
  try {
    const value = storage?.getItem(THEME_STORAGE_KEY);
    return themes.has(value) ? value : "system";
  } catch {
    return "system";
  }
}

export function setThemePreference(storage, preference) {
  if (!themes.has(preference)) return;
  try {
    storage?.setItem(THEME_STORAGE_KEY, preference);
  } catch {
    // The interface remains usable when browser storage is unavailable.
  }
}

export function resolveTheme(preference, prefersDark) {
  return preference === "system" ? (prefersDark ? "dark" : "light") : preference;
}
