export type EventTranslation = { title: string; summary: string; description: string; updated_at?: string };

export type EventQuestionOption = { value: string; label: string };

export type EventQuestionTranslation = {
  label: string;
  help_text: string;
  options: EventQuestionOption[];
};

export type EventQuestion = {
  id: number;
  event_id?: number;
  position?: number;
  type: "short_text" | "long_text" | "single_choice" | "multi_choice";
  required: boolean;
  min_choices: number;
  max_choices: number;
  label: string;
  help_text: string;
  options: EventQuestionOption[];
  translations: Record<string, EventQuestionTranslation>;
  filled_locales?: string[];
  missing_locales?: string[];
};

export type EventStats = {
  capacity: number | null;
  confirmed: number;
  waitlisted: number;
  attended: number;
  no_show: number;
  cancelled: number;
  taken: number;
  remaining: number | null;
  total: number;
};

export type EventMediaItem = {
  id: number;
  attachment_id: number;
  url: string;
  thumb_url: string;
  caption?: string;
};

export type QuestionDraft = {
  id: number;
  type: EventQuestion["type"];
  required: boolean;
  min_choices: number;
  max_choices: number;
  translations: Record<string, EventQuestionTranslation>;
};

export type EventDraft = {
  id: number;
  slug: string;
  status: "draft" | "published" | "archived";
  starts_at: string;
  ends_at: string;
  location_name: string;
  location_address: string;
  map_url: string;
  cover_media_id: number;
  cover_url: string;
  gallery_visible: boolean;
  capacity: string;
  waitlist_enabled: boolean;
  registration_enabled: boolean;
  registration_closes_at: string;
  revision: number;
  translations: Record<string, EventTranslation>;
  questions: QuestionDraft[];
  gallery: EventMediaItem[];
  stats: EventStats | null;
  missing_locales: string[];
};

export type EventListItem = {
  id: number;
  slug: string;
  status: EventDraft["status"];
  state: "upcoming" | "ongoing" | "ended";
  starts_at: string;
  ends_at: string;
  title: string;
  summary: string;
  cover_url: string;
  gallery_visible: boolean;
  capacity: number | null;
  registration_open: boolean;
  revision: number;
  updated_at: string;
  gallery_count: number;
  question_count: number;
  stats: EventStats;
  missing_locales: string[];
};

export type EventSettings = {
  locales: string[];
  notification_email: string;
  max_questions: number;
  max_options: number;
  max_media: number;
  max_capacity: number;
  max_image_bytes: number;
  last_compiled: string | null;
};

export type RegistrationAnswer = {
  question_id: number;
  type: EventQuestion["type"];
  options: string[];
  text: string;
};

export type EventRegistration = {
  id: number;
  event_id: number;
  event_slug: string;
  code: string;
  status: "confirmed" | "waitlisted" | "cancelled" | "attended" | "no_show";
  name: string;
  email: string | null;
  phone: string | null;
  contact_channel: "email" | "phone";
  locale: string;
  note: string;
  waitlist_position: number;
  email_status: string;
  created_at: string;
  updated_at: string;
  confirmed_at: string | null;
  answers: RegistrationAnswer[];
};

export type RegistrationListResponse = {
  items: EventRegistration[];
  total: number;
  page: number;
  per_page: number;
  total_pages: number;
  summary: EventStats;
  answers_summary: {
    question_id: number;
    label: string;
    type: EventQuestion["type"];
    options: { value: string; label: string; count: number }[];
  }[];
};
