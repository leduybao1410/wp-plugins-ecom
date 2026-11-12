"use client";

import { useState } from "react";
import { normaliseDescriptionHtml, sanitizePreviewHtml } from "@/lib/event-workflows.js";

/**
 * Two-mode text field for the event description: write the source (HTML or
 * plain text) and switch to a rendered preview of exactly what the storefront
 * will show. Plain text is normalised into paragraphs on save, so pasting a
 * social post does not end up as one wall of text.
 *
 * Preview markup is scrubbed client-side (scripts, inline handlers,
 * `javascript:` URLs) because it renders text that has not been through
 * WordPress's `wp_kses_post` yet.
 */
export function RichTextField({
  id,
  label,
  value,
  onChange,
  hint,
  rows = 8,
  previewClassName = "prose-event",
}: {
  id: string;
  label: string;
  value: string;
  onChange: (value: string) => void;
  hint?: string;
  rows?: number;
  previewClassName?: string;
}) {
  const [mode, setMode] = useState<"write" | "preview">("write");
  const html = normaliseDescriptionHtml(value);
  const hasTags = /<[a-z][^>]*>/i.test(String(value ?? ""));

  return (
    <div>
      <div className="toolbar" style={{ flexWrap: "wrap", marginTop: 8 }}>
        <label htmlFor={id} style={{ margin: 0 }}>{label}</label>
        <div role="tablist" aria-label={`${label}: chế độ soạn thảo`} style={{ display: "flex", gap: 6 }}>
          <button
            type="button"
            role="tab"
            aria-selected={mode === "write"}
            className={mode === "write" ? "action-button" : "quiet-button"}
            onClick={() => setMode("write")}
            style={{ minHeight: 34 }}
          >
            Văn bản
          </button>
          <button
            type="button"
            role="tab"
            aria-selected={mode === "preview"}
            className={mode === "preview" ? "action-button" : "quiet-button"}
            onClick={() => setMode("preview")}
            style={{ minHeight: 34 }}
          >
            Xem trước
          </button>
        </div>
      </div>

      {mode === "write" ? (
        <>
          <textarea
            id={id}
            className="search-input"
            style={{ minHeight: rows * 24, padding: 10, fontFamily: hasTags ? "ui-monospace, SFMono-Regular, Menlo, monospace" : undefined }}
            value={value}
            onChange={(event) => onChange(event.target.value)}
            spellCheck={!hasTags}
            aria-describedby={hint ? `${id}-hint` : undefined}
          />
          <small className="muted" id={hint ? `${id}-hint` : undefined}>
            {hint ?? "Dán văn bản thường hoặc HTML cơ bản (đoạn văn, danh sách, liên kết, ảnh). Văn bản thường được tự chia đoạn khi lưu."}
          </small>
        </>
      ) : (
        <div
          className={previewClassName}
          aria-live="polite"
          style={{
            minHeight: rows * 24,
            padding: "12px 14px",
            border: "1px solid var(--line)",
            borderRadius: 10,
            background: "var(--surface)",
            fontSize: 14,
            lineHeight: 1.7,
          }}
        >
          {html.trim() ? (
            <div dangerouslySetInnerHTML={{ __html: sanitizePreviewHtml(html) }} />
          ) : (
            <span className="muted">Chưa có nội dung để xem trước.</span>
          )}
        </div>
      )}
    </div>
  );
}
