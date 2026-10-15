/**
 * Shapes of /api/v1 answers (see docs/API.md and app/Modules/* /Api/*.php).
 * Money and numeric(…) columns arrive as strings ("1070.000000"); timestamps as
 * PostgreSQL text ("2026-10-15 10:00:00+07"); dates as "YYYY-MM-DD".
 */

export type Id = number;
export type Decimal = string | number;
export type Timestamp = string;
export type DateString = string;

export interface Page<T> {
  data: T[];
  meta: { page: number; per_page: number; total: number; pages: number };
}

export interface One<T> {
  data: T;
}

/* --------------------------------------------------------------------- core */

export type ModuleKey = 'crm' | 'machines' | 'accounting';
export type Action = 'view' | 'create' | 'edit' | 'delete' | 'download';
export type Permissions = Partial<Record<ModuleKey, Record<string, Action[]>>>;

export interface Profile {
  id: Id;
  name: string;
  email: string;
  role: string | null;
  department: string | null;
  team: string | null;
  job_title: string | null;
  locale: string | null;
  avatar_url: string | null;
  is_admin: boolean;
  modules: ModuleKey[];
  permissions: Permissions;
}

export interface LoginRequest {
  email: string;
  password: string;
  device_name: string;
  device_id?: string;
  platform: 'ios' | 'android' | 'other';
}

export interface Device {
  id: Id;
  name: string;
  platform: string | null;
  created_at: Timestamp;
  last_used_at: Timestamp | null;
  last_ip: string | null;
  expires_at: Timestamp;
  current: boolean;
}

export interface Notification {
  id: Id;
  module: string | null;
  type: string | null;
  title: string;
  body: string | null;
  url: string | null;
  read_at: Timestamp | null;
  created_at: Timestamp;
}

/* ---------------------------------------------------------------------- CRM */

export const OPPORTUNITY_STAGES = [
  'QUALIFICATION',
  'SURVEY_PROPOSAL',
  'EVALUATION_TESTING',
  'NEGOTIATION',
  'CLOSED_WON',
  'CLOSED_LOST',
  'ON_HOLD',
  'CANCEL',
] as const;
export type OpportunityStage = (typeof OPPORTUNITY_STAGES)[number];

export const ACTIVITY_TYPES = ['CALL', 'MEETING', 'EMAIL', 'TASK'] as const;
export type ActivityType = (typeof ACTIVITY_TYPES)[number];
export const ACTIVITY_STATUSES = ['PLANNED', 'DONE', 'CANCELLED'] as const;
export type ActivityStatus = (typeof ACTIVITY_STATUSES)[number];
export const CALL_DIRECTIONS = ['OUTBOUND', 'INBOUND'] as const;
export type CallDirection = (typeof CALL_DIRECTIONS)[number];
export const MEETING_TYPES = ['ONSITE', 'CUSTOMER_SITE', 'ONLINE'] as const;
export type MeetingType = (typeof MEETING_TYPES)[number];
export const NOTIFY_BEFORE = [0, 5, 15, 30, 60, 1440] as const;
export const TASK_STATUSES = ['TODO', 'IN_PROGRESS', 'BLOCKED', 'DONE', 'CANCELLED'] as const;
export type TaskStatus = (typeof TASK_STATUSES)[number];

export interface Lead {
  id: Id;
  code: string | null;
  name_en: string;
  name_th: string | null;
  tax_id: string | null;
  phone: string | null;
  mobile: string | null;
  fax: string | null;
  address: string | null;
  city: string | null;
  province: string | null;
  country: string | null;
  zipcode: string | null;
  is_register: boolean | null;
  is_government: boolean | null;
  owner_id: Id | null;
  owner: string | null;
  department_id: Id | null;
  department: string | null;
  created_at: Timestamp;
  updated_at: Timestamp;
}

export interface Contact {
  id: Id;
  code: string | null;
  salutation: string | null;
  name_en: string;
  name_th: string | null;
  job_title: string | null;
  department: string | null;
  phone: string | null;
  phone_ext: string | null;
  email: string | null;
  lead_id: Id | null;
  lead_name: string | null;
  owner_id: Id | null;
  owner: string | null;
  updated_at: Timestamp;
}

export interface ContactMobile {
  number: string;
  ext: string | null;
  label: string | null;
  is_primary: boolean;
}

export interface ContactDetail extends Contact {
  mobiles: ContactMobile[];
}

export interface Opportunity {
  id: Id;
  code: string | null;
  name: string;
  opportunity_stage: OpportunityStage;
  stage_label: string;
  probability: Decimal | null;
  amount: Decimal | null;
  currency: string | null;
  priority: string | null;
  follow_at: Timestamp | null;
  close_at: Timestamp | null;
  cancel_reason: string | null;
  lead_id: Id | null;
  lead_name: string | null;
  contact_id: Id | null;
  contact_name: string | null;
  owner_id: Id | null;
  owner: string | null;
  updated_at: Timestamp;
}

export interface Activity {
  id: Id;
  code: string | null;
  activity_type: ActivityType;
  topic: string;
  status: ActivityStatus;
  start_at: Timestamp | null;
  call_direction: CallDirection | null;
  call_duration: number | null;
  meeting_type: MeetingType | null;
  meeting_duration: number | null;
  meeting_location: string | null;
  notify_me: boolean;
  notify_before: number | null;
  description: string | null;
  lead_id: Id | null;
  lead_name: string | null;
  contact_id: Id | null;
  contact_name: string | null;
  opportunity_id: Id | null;
  opportunity_name: string | null;
  owner_id: Id | null;
  owner: string | null;
  updated_at: Timestamp;
}

/** POST /crm/activities and /crm/activities/{id}. */
export interface ActivityInput {
  activity_type: ActivityType;
  topic: string;
  status: ActivityStatus;
  start_at?: string | null;
  call_direction?: CallDirection | null;
  call_duration?: number | null;
  meeting_type?: MeetingType | null;
  meeting_duration?: number | null;
  meeting_location?: string | null;
  lead_id: Id;
  contact_id?: Id | null;
  opportunity_id?: Id | null;
  notify_me?: boolean;
  notify_before?: number;
  description?: string | null;
}

export interface Task {
  id: Id;
  code: string | null;
  name: string;
  description: string | null;
  status: TaskStatus;
  start_date: DateString | null;
  end_date: DateString | null;
  project_id: Id | null;
  project_name: string | null;
  assignees: { id: Id; name: string | null }[];
  owner_id: Id | null;
  owner: string | null;
  updated_at: Timestamp;
}

export interface LeadDetail {
  data: Lead;
  contacts?: Contact[];
  opportunities?: Opportunity[];
  activities?: Activity[];
}

export interface SearchHit {
  id: Id;
  label: string;
  sub: string | null;
}

export interface CrmSearch {
  leads?: SearchHit[];
  contacts?: SearchHit[];
  opportunities?: SearchHit[];
}

export interface CrmStats {
  pipeline?: { stage: OpportunityStage; currency: string | null; count: number; amount: Decimal; weighted: Decimal }[];
  won_this_year?: { currency: string | null; count: number; amount: Decimal }[];
  activities?: { overdue: number; today: number; next_7_days: number; done_this_month: number } | null;
  my_tasks?: { open: number; late: number } | null;
}

/* --------------------------------------------------------- Machine Checklist */

export const MACHINE_STATUSES = ['OPERATIONAL', 'UNDER MAINTENANCE'] as const;
export type MachineStatus = string;
export type Answer = 'OK' | 'NG' | 'NA';

export interface Machine {
  id: Id;
  machine_code: string;
  machine_name: string;
  machine_group_name: string | null;
  machine_type_name: string | null;
  brand: string | null;
  model: string | null;
  serial_number: string | null;
  machine_number: string | null;
  machine_status: MachineStatus;
  check_status: string;
  department_id: Id | null;
  is_calibration: boolean;
  reset_period: string | null;
  maintenance_period: string | null;
  image_url: string | null;
  note: string | null;
}

export interface Person {
  id: Id;
  name: string | null;
}

export interface ChecklistItem {
  id: Id;
  question: string;
  description: string | null;
  answer_type: 'choice' | 'text';
  choices: Answer[] | null;
}

export interface MachineScreen {
  machine: Machine;
  people: { responsible: Person | null; supervisor: Person | null; manager: Person | null };
  checklist: {
    can_check: boolean;
    recheck: boolean;
    weekend: boolean;
    machine_statuses: MachineStatus[];
    items: ChecklistItem[];
  } | null;
  last_checks: {
    id: Id;
    check_type: string;
    recheck: boolean;
    checklist_status: string;
    machine_status: MachineStatus;
    user_name: string | null;
    created_at: Timestamp;
  }[];
  next_maintenance: DateString | null;
  next_calibration: DateString | null;
}

export interface CheckRecord {
  id: Id;
  check_type: string;
  recheck: boolean;
  machine_code: string;
  machine_name: string;
  machine_status: MachineStatus;
  checklist_status: string;
  machine_note: string | null;
  user_name: string | null;
  created_at: Timestamp;
  date_supervisor_checked: Timestamp | null;
  date_manager_checked: Timestamp | null;
  reason_not_checked: string | null;
  can_approve: boolean;
  /** Only on GET /machines/checklists/{id} and after saving. */
  answers?: { question: string | null; answer: string | null; remark: string | null }[];
  image_url?: string | null;
}

/** answers[<item id>] = {answer, remark?} */
export interface CheckInput {
  machine_status: MachineStatus;
  answers: Record<string, { answer: string; remark?: string }>;
  machine_note?: string;
}

export interface DueItem {
  id: Id;
  machine_code: string;
  machine_name: string | null;
  maintenance_type?: string;
  due_date: DateString;
  plan_date?: DateString | null;
  start_date: DateString | null;
  overdue: boolean;
}

export interface DueList {
  maintenance?: DueItem[];
  calibration?: DueItem[];
}

/* --------------------------------------------------------------- Accounting */

export interface Customer {
  id: Id;
  code: string | null;
  name: string;
  alternative_name: string | null;
  vat_number: string | null;
  corporation_identification_number: string | null;
  is_private_customer: boolean | null;
  credit_limit: Decimal | null;
  address: string | null;
  seller_name: string | null;
}

export interface CustomerLinks {
  invoices?: { invoice_number: string; invoice_date: Timestamp | null; currency_code: string | null; amount: Decimal }[];
  orders?: { id: Id; order_number: string | number | null; business_contact_order_number: string | null; order_date: Timestamp | null; status: string | number | null; is_credit: boolean | null }[];
  inets?: { id: Id; no_invoice: number; invoice_date: Timestamp | null; is_credit: boolean; total_amount: Decimal; currency_code: string | null }[];
  billing_notes?: { id: Id; billing_number: string; billing_at: Timestamp | null; total_amount: Decimal; currency_code: string | null; is_paid: boolean; is_complete: boolean }[];
  crm_leads?: { id: Id; code: string | null; name_en: string }[];
}

export interface CustomerDetail {
  data: Customer;
  links: CustomerLinks;
}

export interface Product {
  id: Id;
  part_number: string;
  description: string | null;
  alias: string | null;
  standard_price: Decimal | null;
  part_template_id?: Id | null;
  part_code_id?: Id | null;
}

export interface Order {
  id: Id;
  order_number: string | number | null;
  business_contact_order_number: string | null;
  order_date: Timestamp | null;
  vat_number: string | null;
  is_credit: boolean | null;
  status: string | number | null;
  customer_id: Id | null;
  customer_code: string | null;
  customer_name: string | null;
  seller_name: string | null;
  total: Decimal | null;
  rows?: {
    row_index: number | null;
    part_number: string | null;
    description: string | null;
    ordered_quantity: Decimal | null;
    price: Decimal | null;
    discount: Decimal | null;
    delivery_date: Timestamp | null;
  }[];
}

export interface Invoice {
  invoice_number: string | number;
  invoice_date: Timestamp | null;
  customer_id: Id | null;
  customer_code: string | null;
  customer_name: string | null;
  customer_order_number: string | null;
  currency_code: string | null;
  seller: string | null;
  line_count: number;
  amount: Decimal;
  lines?: { part_number: string | null; invoiced_quantity: Decimal | null; price: Decimal | null; discount: Decimal | null; unit_code: string | null }[];
}

export interface Seller {
  id: Id;
  name: string | null;
  employee_number: string | null;
  phone_number: string | null;
  email: string | null;
  department: string | null;
}

export type InetDocType = '388' | 't01' | '81';

export interface InetDocument {
  type: InetDocType;
  label: string;
  generated: boolean;
  sent: boolean;
  signed: boolean;
  message: string | null;
  pdf_url: string | null;
  signed_pdf_url: string | null;
}

export interface Inet {
  id: Id;
  no_invoice: number;
  custom_invoice_no: string | null;
  invoice_date: Timestamp | null;
  is_credit: boolean;
  is_international: boolean;
  customer_id: Id | null;
  customer_code: string | null;
  customer_name: string | null;
  customer_order_number: string | null;
  seller_name: string | null;
  currency_code: string | null;
  amount: Decimal;
  tax_amount: Decimal;
  total_amount: Decimal;
  documents: InetDocument[];
  // detail only
  tax_id?: string | null;
  branch?: string | null;
  email?: string | null;
  delivery_note_number?: number | null;
  department_name?: string | null;
  exchange_rate?: Decimal | null;
  tax_rate?: Decimal | null;
  product_count?: number;
  remark?: string | null;
}

export type BillingStatus = 'pending' | 'overdue' | 'paid' | 'complete';

export interface BillingNote {
  id: Id;
  billing_number: string;
  billing_at: Timestamp | null;
  remind_date: DateString | null;
  customer_id: string | number | null;
  customer_code: string | null;
  customer_name: string | null;
  vat_number: string | null;
  total_amount: Decimal;
  currency_code: string | null;
  is_paid: boolean;
  is_complete: boolean;
  status?: BillingStatus;
  invoice_count?: number;
  has_pdf?: boolean;
  pdf_url: string | null;
  // detail only
  is_thai?: boolean;
  address?: string | null;
  note?: string | null;
  remark?: string | null;
  created_at?: Timestamp;
  invoices?: { invoice_number: string; order_no: string | null; invoice_date: Timestamp | null; due_date: Timestamp | null; amount: Decimal; vat_amount: Decimal; is_credit: boolean }[];
}

export interface AccountingStats {
  built: string | null;
  finance?: {
    year?: number;
    by_year?: { y: number; v: Decimal }[];
    month_cur?: number[];
    month_prev?: number[];
    kpi?: { month: number; month_prev_year: number; month_last: number; ytd: number; ytd_prev: number; invoices_month: number };
    customers?: { name: string; v: Decimal }[];
    sellers?: { name: string; v: Decimal }[];
    products?: { name: string; v: Decimal }[];
    aging?: { b: string; v: Decimal }[];
    receivable?: number;
  };
  orders?: { orders_month?: { ym: string; n: number }[]; orders_this_month?: number };
  inet?: {
    inet?: { waiting: number; processing: number; signed: number; not_generated: number; total: number } | [];
    inet_month?: { ym: string; n: number }[];
    not_in_inet?: number;
  };
  billing?: {
    billing_status?: { s: BillingStatus; n: number; v: Decimal }[];
    billing_month?: { ym: string; v: Decimal }[];
    billing_today?: number;
    billing_open?: number;
  };
}
