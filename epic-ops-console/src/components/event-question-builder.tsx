"use client";

import { useState } from "react";
import {
  EVENT_LOCALES,
  EVENT_LOCALE_LABELS,
  QUESTION_TYPES,
  QUESTION_TYPE_LABELS,
  emptyQuestion,
  isChoiceQuestion,
  slugifyOption,
} from "@/lib/event-workflows.js";
import type { QuestionDraft } from "@/lib/event-types";

type Props = {
  questions: QuestionDraft[];
  onChange: (questions: QuestionDraft[]) => void;
};

export function EventQuestionBuilder({ questions, onChange }: Props) {
  const [locale, setLocale] = useState<"vi" | "en">("vi");

  function update(index: number, patch: Partial<QuestionDraft>) {
    onChange(questions.map((question, position) => (position === index ? { ...question, ...patch } : question)));
  }

  function updateTranslation(index: number, field: "label" | "help_text", value: string) {
    onChange(questions.map((question, position) => {
      if (position !== index) return question;
      return { ...question, translations: { ...question.translations, [locale]: { ...question.translations[locale], [field]: value } } };
    }));
  }

  function updateOption(index: number, optionIndex: number, patch: { label?: string; value?: string }) {
    onChange(questions.map((question, position) => {
      if (position !== index) return question;
      const options = [...(question.translations[locale]?.options ?? [])];
      const current = options[optionIndex] ?? { value: "", label: "" };
      options[optionIndex] = { ...current, ...patch };
      return { ...question, translations: { ...question.translations, [locale]: { ...question.translations[locale], options } } };
    }));
  }

  function addOption(index: number) {
    onChange(questions.map((question, position) => {
      if (position !== index) return question;
      const options = [...(question.translations[locale]?.options ?? [])];
      if (options.length >= 20) return question;
      options.push({ value: `option-${options.length + 1}`, label: "" });
      return { ...question, translations: { ...question.translations, [locale]: { ...question.translations[locale], options } } };
    }));
  }

  function removeOption(index: number, optionIndex: number) {
    onChange(questions.map((question, position) => {
      if (position !== index) return question;
      const options = (question.translations[locale]?.options ?? []).filter((_option, position2) => position2 !== optionIndex);
      return { ...question, translations: { ...question.translations, [locale]: { ...question.translations[locale], options } } };
    }));
  }

  function move(index: number, direction: -1 | 1) {
    const target = index + direction;
    if (target < 0 || target >= questions.length) return;
    const next = [...questions];
    [next[index], next[target]] = [next[target], next[index]];
    onChange(next);
  }

  return <div className="detail-section">
    <div className="toolbar" style={{ flexWrap: "wrap" }}>
      <h3 style={{ margin: 0 }}>Form đăng ký</h3>
      <span className="muted">{questions.length} câu hỏi</span>
    </div>
    <p className="muted" style={{ marginTop: 4 }}>
      Họ tên, số điện thoại và email luôn có sẵn trong form. Thêm câu hỏi để thu thập thông tin hoặc ý kiến khách hàng.
    </p>

    <div className="toolbar" role="tablist" aria-label="Ngôn ngữ câu hỏi" style={{ flexWrap: "wrap" }}>
      {EVENT_LOCALES.map((code) => <button key={code} type="button" role="tab" aria-selected={locale === code} className={locale === code ? "action-button" : "quiet-button"} onClick={() => setLocale(code as "vi" | "en")}>{EVENT_LOCALE_LABELS[code as keyof typeof EVENT_LOCALE_LABELS]}</button>)}
      <button className="quiet-button" type="button" onClick={() => onChange([...questions, emptyQuestion() as QuestionDraft])}>Thêm câu hỏi</button>
    </div>

    {questions.map((question, index) => {
      const choice = isChoiceQuestion(question.type);
      const options = question.translations[locale]?.options ?? [];
      return <section key={`${question.id}-${index}`} className="card section-card" style={{ marginTop: 12 }}>
        <div className="toolbar" style={{ flexWrap: "wrap" }}>
          <strong>Câu {index + 1}</strong>
          <div className="toolbar" style={{ gap: 4 }}>
            <button className="quiet-button" type="button" aria-label="Lên" disabled={index === 0} onClick={() => move(index, -1)} style={{ minHeight: 32, padding: "0 9px" }}>↑</button>
            <button className="quiet-button" type="button" aria-label="Xuống" disabled={index === questions.length - 1} onClick={() => move(index, 1)} style={{ minHeight: 32, padding: "0 9px" }}>↓</button>
            <button className="quiet-button" type="button" onClick={() => onChange(questions.filter((_item, position) => position !== index))} style={{ minHeight: 32 }}>Xoá</button>
          </div>
        </div>

        <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 10, marginTop: 8 }}>
          <div>
            <label htmlFor={`question-type-${index}`}>Loại câu hỏi</label>
            <select
              id={`question-type-${index}`}
              className="search-input"
              value={question.type}
              onChange={(event) => {
                const type = event.target.value as QuestionDraft["type"];
                update(index, { type, min_choices: type === "single_choice" ? 0 : question.min_choices, max_choices: type === "single_choice" ? 0 : question.max_choices });
              }}
            >
              {QUESTION_TYPES.map((type) => <option key={type} value={type}>{QUESTION_TYPE_LABELS[type as keyof typeof QUESTION_TYPE_LABELS]}</option>)}
            </select>
          </div>
          <div style={{ display: "flex", alignItems: "end", gap: 14, paddingBottom: 8 }}>
            <label><input type="checkbox" checked={question.required} onChange={(event) => update(index, { required: event.target.checked })} /> Bắt buộc</label>
          </div>
        </div>

        <label htmlFor={`question-label-${index}`} style={{ display: "block", marginTop: 8 }}>Nội dung câu hỏi ({EVENT_LOCALE_LABELS[locale as keyof typeof EVENT_LOCALE_LABELS]})</label>
        <input
          id={`question-label-${index}`}
          className="search-input"
          maxLength={300}
          value={question.translations[locale]?.label ?? ""}
          onChange={(event) => updateTranslation(index, "label", event.target.value)}
        />
        <label htmlFor={`question-help-${index}`} style={{ display: "block", marginTop: 8 }}>Gợi ý (không bắt buộc)</label>
        <input
          id={`question-help-${index}`}
          className="search-input"
          maxLength={500}
          value={question.translations[locale]?.help_text ?? ""}
          onChange={(event) => updateTranslation(index, "help_text", event.target.value)}
        />

        {choice && <>
          <div className="toolbar" style={{ marginTop: 12 }}><strong>Lựa chọn</strong><span className="muted">{options.length}/20</span></div>
          <div style={{ display: "grid", gap: 6 }}>
            {options.map((option, optionIndex) => <div key={optionIndex} style={{ display: "grid", gridTemplateColumns: "1fr 132px 40px", gap: 6 }}>
              <input
                className="search-input"
                aria-label={`Nhãn lựa chọn ${optionIndex + 1}`}
                placeholder="Nhãn hiển thị"
                maxLength={200}
                value={option.label}
                onChange={(event) => {
                  const label = event.target.value;
                  const patch: { label: string; value?: string } = { label };
                  if (!option.value || option.value === slugifyOption(option.label)) patch.value = slugifyOption(label) || `option-${optionIndex + 1}`;
                  updateOption(index, optionIndex, patch);
                }}
              />
              <input
                className="search-input"
                aria-label={`Mã lựa chọn ${optionIndex + 1}`}
                placeholder="ma-lua-chon"
                maxLength={64}
                value={option.value}
                onChange={(event) => updateOption(index, optionIndex, { value: slugifyOption(event.target.value) })}
              />
              <button className="quiet-button" type="button" aria-label="Xoá lựa chọn" disabled={options.length <= 2} onClick={() => removeOption(index, optionIndex)}>✕</button>
            </div>)}
          </div>
          <div className="toolbar" style={{ marginTop: 8 }}>
            <button className="quiet-button" type="button" disabled={options.length >= 20} onClick={() => addOption(index)}>Thêm lựa chọn</button>
            {locale === "vi" && <span className="muted">Mã lựa chọn giữ nguyên khi đổi nhãn hoặc dịch.</span>}
          </div>
          {question.type === "multi_choice" && <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 10, marginTop: 8 }}>
            <div>
              <label htmlFor={`question-min-${index}`}>Chọn ít nhất</label>
              <input id={`question-min-${index}`} className="search-input" type="number" min={0} max={options.length} value={question.min_choices} onChange={(event) => update(index, { min_choices: Number(event.target.value) || 0 })} />
            </div>
            <div>
              <label htmlFor={`question-max-${index}`}>Chọn nhiều nhất (0 = không giới hạn)</label>
              <input id={`question-max-${index}`} className="search-input" type="number" min={0} max={options.length} value={question.max_choices} onChange={(event) => update(index, { max_choices: Number(event.target.value) || 0 })} />
            </div>
          </div>}
        </>}
      </section>;
    })}

    {!questions.length && <p className="muted">Chưa có câu hỏi. Form chỉ thu họ tên, số điện thoại/email và ghi chú.</p>}
    <small className="muted">Mã lựa chọn của bản {EVENT_LOCALE_LABELS[locale as keyof typeof EVENT_LOCALE_LABELS]} phải trùng với bản còn lại để đáp án không bị tách.</small>
  </div>;
}
