import { Children, isValidElement, type ChangeEvent, type ReactNode } from "react";
import * as Select from "@radix-ui/react-select";
import { Check, ChevronDown } from "lucide-react";

const EMPTY = "__epic_empty_option__";
type OptionProps = { value: string | number; children: ReactNode; disabled?: boolean };
type Props = { children: ReactNode; value: string; onChange: (event: ChangeEvent<HTMLSelectElement>) => void; required?: boolean; disabled?: boolean };

/** Preserves the workspace's controlled-select contract and native form validation. */
export function TranslationSelect({ children, value, onChange, required, disabled }: Props) {
  const options = Children.toArray(children).filter((child) => isValidElement<OptionProps>(child) && child.type === "option") as React.ReactElement<OptionProps>[];
  const emptyLabel = options.find((option) => String(option.props.value) === "")?.props.children ?? "Chọn một mục";
  const selected = value === "" ? (required ? "" : EMPTY) : value;
  return <Select.Root value={selected} required={required} disabled={disabled} onValueChange={(next) => onChange({ target: { value: next === EMPTY ? "" : next } } as ChangeEvent<HTMLSelectElement>)}>
    <Select.Trigger className="translation-select-trigger"><Select.Value placeholder={emptyLabel} /><Select.Icon aria-hidden="true"><ChevronDown size={17} /></Select.Icon></Select.Trigger>
    <Select.Portal><Select.Content className="translation-select-content" position="popper" sideOffset={5} collisionPadding={10}>
      <Select.Viewport className="translation-select-options">{options.filter((option) => !required || String(option.props.value) !== "").map((option) => <Select.Item className="translation-select-option" key={String(option.props.value)} value={String(option.props.value) || EMPTY} disabled={option.props.disabled}><Select.ItemText>{option.props.children}</Select.ItemText><Select.ItemIndicator className="translation-select-check"><Check size={16} /></Select.ItemIndicator></Select.Item>)}</Select.Viewport>
    </Select.Content></Select.Portal>
  </Select.Root>;
}
