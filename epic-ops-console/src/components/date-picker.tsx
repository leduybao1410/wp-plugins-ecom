"use client";

import { useState } from "react";
import * as Popover from "@radix-ui/react-popover";
import { CalendarDays, ChevronDown, ChevronLeft, ChevronRight } from "lucide-react";
import { DayPicker } from "react-day-picker";
import { vi } from "react-day-picker/locale";
import { localToday } from "../lib/overview-query.js";

type DatePickerProps = {
  id: string;
  label: string;
  value: string;
  onChange: (value: string) => void;
  min?: string;
  max?: string;
  disabled?: boolean;
  clearable?: boolean;
};

function parseDate(value?: string) {
  if (!value || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return undefined;
  const date = new Date(`${value}T12:00:00`);
  return Number.isNaN(date.getTime()) || isoDate(date) !== value ? undefined : date;
}

function isoDate(date: Date) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
}

/** Locale dates in the interface; unchanged YYYY-MM-DD values at every API boundary. */
export function DatePicker({ id, label, value, onChange, min, max, disabled, clearable = true }: DatePickerProps) {
  const [open, setOpen] = useState(false);
  const selected = parseDate(value);
  const minDate = parseDate(min);
  const maxDate = parseDate(max);
  const today = parseDate(localToday())!;
  const displayed = selected ? new Intl.DateTimeFormat("vi-VN", { day: "2-digit", month: "2-digit", year: "numeric" }).format(selected) : "Chọn ngày";
  const todayDisabled = Boolean(minDate && today < minDate || maxDate && today > maxDate);
  function choose(next: string) { onChange(next); setOpen(false); }

  return <Popover.Root open={open} onOpenChange={setOpen} modal>
    <Popover.Trigger asChild><button id={id} type="button" className={`search-input date-picker-trigger${selected ? " has-value" : ""}`} disabled={disabled} aria-label={`${label}: ${displayed}`}><CalendarDays size={17} aria-hidden="true" /><span>{displayed}</span><ChevronDown size={14} aria-hidden="true" /></button></Popover.Trigger>
    <input type="hidden" name={id} value={value} />
    <Popover.Portal><Popover.Content className="date-picker-popover" align="start" sideOffset={8} collisionPadding={12} aria-label={`Chọn ${label}`}>
      <div className="date-picker-heading"><CalendarDays size={16} aria-hidden="true" /><span>{label}</span><small>Ngày / Tháng / Năm</small></div>
      <DayPicker className="epic-calendar" mode="single" required autoFocus locale={vi} weekStartsOn={1} today={today} selected={selected} defaultMonth={selected ?? today} showOutsideDays
        startMonth={minDate} endMonth={maxDate}
        disabled={[...(minDate ? [{ before: minDate }] : []), ...(maxDate ? [{ after: maxDate }] : [])]}
        formatters={{ formatCaption: (date) => `Tháng ${date.getMonth() + 1}, ${date.getFullYear()}`, formatWeekdayName: (date) => ["CN", "T2", "T3", "T4", "T5", "T6", "T7"][date.getDay()] }}
        labels={{ labelPrevious: () => "Tháng trước", labelNext: () => "Tháng sau", labelDayButton: (date, modifiers) => `${new Intl.DateTimeFormat("vi-VN", { dateStyle: "full" }).format(date)}${modifiers.today ? ", hôm nay" : ""}${modifiers.selected ? ", đã chọn" : ""}` }}
        components={{ Chevron: ({ orientation }) => orientation === "left" ? <ChevronLeft size={18} aria-hidden="true" /> : <ChevronRight size={18} aria-hidden="true" /> }}
        onSelect={(date) => { if (date) choose(isoDate(date)); }} />
      <div className="date-picker-footer"><button className="quiet-button" type="button" disabled={todayDisabled} onClick={() => choose(isoDate(today))}>Hôm nay</button>{clearable && <button className="date-picker-clear" type="button" disabled={!value} onClick={() => choose("")}>Xóa ngày</button>}</div>
    </Popover.Content></Popover.Portal>
  </Popover.Root>;
}
