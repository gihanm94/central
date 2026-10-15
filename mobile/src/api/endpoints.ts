import type { ApiClient, Download, Query } from './client';
import { ApiError } from './errors';
import type { TokenResponse } from './tokens';
import type {
  AccountingStats,
  Activity,
  ActivityInput,
  ActivityStatus,
  BillingNote,
  BillingStatus,
  CheckInput,
  CheckRecord,
  Contact,
  ContactDetail,
  CrmSearch,
  CrmStats,
  Customer,
  CustomerDetail,
  Device,
  DueList,
  Inet,
  InetDocType,
  Invoice,
  Lead,
  LeadDetail,
  LoginRequest,
  Machine,
  MachineScreen,
  Notification,
  One,
  Opportunity,
  OpportunityStage,
  Order,
  Page,
  Product,
  Profile,
  Seller,
  Task,
  TaskStatus,
} from './types';

/** A photo to upload: a React Native file reference, or a Blob (tests, Node). */
export type UploadFile = { uri: string; name: string; type: string } | { blob: Blob; name: string };

export interface ListQuery extends Query {
  q?: string;
  page?: number;
  per_page?: number;
}

/** Longest text the scan endpoint looks at (the server cuts at 500 characters). */
export const MAX_SCAN_LENGTH = 500;

const enc = encodeURIComponent;

/** Every endpoint of docs/API.md, typed. */
export function createApi(client: ApiClient) {
  return {
    auth: {
      /** No token needed. Errors: 401 invalid_credentials, 403 no_api_access, 429 locked / rate_limited, 422 invalid. */
      login: (body: LoginRequest) => client.post<TokenResponse & { user: Profile }>('/auth/login', body, { auth: false }),
      logout: () => client.post<{ ok: true }>('/auth/logout'),
    },

    me: {
      get: () => client.get<One<Profile>>('/me'),
      devices: () => client.get<{ data: Device[] }>('/me/devices'),
      revokeDevice: (id: number) => client.post<{ ok: true }>(`/me/devices/${id}/revoke`),
      notifications: (q: ListQuery & { unread?: boolean } = {}) => client.get<Page<Notification>>('/me/notifications', q),
    },

    crm: {
      stats: () => client.get<One<CrmStats>>('/crm/stats'),
      search: (q: string) => client.get<One<CrmSearch | []>>('/crm/search', { q }),
      leads: (q: ListQuery & { mine?: boolean } = {}) => client.get<Page<Lead>>('/crm/leads', q),
      lead: (id: number) => client.get<LeadDetail>(`/crm/leads/${id}`),
      contacts: (q: ListQuery & { lead_id?: number } = {}) => client.get<Page<Contact>>('/crm/contacts', q),
      contact: (id: number) => client.get<One<ContactDetail>>(`/crm/contacts/${id}`),
      opportunities: (q: ListQuery & { stage?: OpportunityStage; open?: boolean; lead_id?: number; mine?: boolean } = {}) =>
        client.get<Page<Opportunity>>('/crm/opportunities', q),
      opportunity: (id: number) => client.get<One<Opportunity>>(`/crm/opportunities/${id}`),
      activities: (
        q: ListQuery & { status?: ActivityStatus; type?: string; from?: string; to?: string; mine?: boolean; lead_id?: number } = {},
      ) => client.get<Page<Activity>>('/crm/activities', q),
      activity: (id: number) => client.get<One<Activity>>(`/crm/activities/${id}`),
      createActivity: (input: ActivityInput) => client.post<One<Activity>>('/crm/activities', input),
      /** Fields left out keep their value. */
      updateActivity: (id: number, input: Partial<ActivityInput>) => client.post<One<Activity>>(`/crm/activities/${id}`, input),
      setActivityStatus: (id: number, status: ActivityStatus) => client.post<One<Activity>>(`/crm/activities/${id}/status`, { status }),
      tasks: (q: ListQuery & { status?: TaskStatus; open?: boolean; mine?: boolean; project_id?: number } = {}) =>
        client.get<Page<Task>>('/crm/tasks', q),
      task: (id: number) => client.get<One<Task>>(`/crm/tasks/${id}`),
      setTaskStatus: (id: number, status: TaskStatus) => client.post<One<Task>>(`/crm/tasks/${id}/status`, { status }),
    },

    machines: {
      /** What the camera read: JSON QR, a /machines/m/{code} link or a plain code. 404 machine_not_found. */
      scan: (text: string) => client.post<One<MachineScreen>>('/machines/scan', { text }),
      find: (q: string) => client.get<{ data: Machine[] }>('/machines/find', { q }),
      machine: (code: string) => client.get<One<MachineScreen>>(`/machines/m/${enc(code)}`),
      /** JSON without a photo, multipart/form-data with one. */
      submitCheck: (code: string, input: CheckInput, photo?: UploadFile) =>
        client.post<One<CheckRecord>>(`/machines/m/${enc(code)}/check`, photo ? checkFormData(input, photo) : input),
      checklists: (q: ListQuery & { pending?: boolean } = {}) => client.get<Page<CheckRecord>>('/machines/checklists', q),
      checklist: (id: number) => client.get<One<CheckRecord>>(`/machines/checklists/${id}`),
      approve: (id: number, note?: string) => client.post<One<CheckRecord>>(`/machines/checklists/${id}/approve`, { note: note ?? '' }),
      due: (days = 30) => client.get<{ data: DueList }>('/machines/due', { days }),
    },

    accounting: {
      stats: () => client.get<One<AccountingStats>>('/accounting/stats'),
      customers: (q: ListQuery = {}) => client.get<Page<Customer>>('/accounting/customers', q),
      customer: (id: number) => client.get<CustomerDetail>(`/accounting/customers/${id}`),
      products: (q: ListQuery = {}) => client.get<Page<Product>>('/accounting/products', q),
      product: (id: number) => client.get<One<Product>>(`/accounting/products/${id}`),
      orders: (q: ListQuery & { customer_id?: number; credit?: '0' | '1' } = {}) => client.get<Page<Order>>('/accounting/orders', q),
      order: (id: number) => client.get<One<Order>>(`/accounting/orders/${id}`),
      invoices: (q: ListQuery & { customer_id?: number } = {}) => client.get<Page<Invoice>>('/accounting/invoices', q),
      invoice: (number: string) => client.get<One<Invoice>>(`/accounting/invoices/${enc(number)}`),
      sellers: (q: ListQuery = {}) => client.get<Page<Seller>>('/accounting/sellers', q),
      inets: (q: ListQuery & { credit?: boolean; send?: 'sent' | 'unsent'; customer_id?: number } = {}) =>
        client.get<Page<Inet>>('/accounting/inets', q),
      inet: (id: number) => client.get<One<Inet>>(`/accounting/inets/${id}`),
      inetPdf: (id: number, doc: InetDocType, signed = false) =>
        client.download(`/accounting/inets/${id}/pdf/${doc}`, { signed: signed ? 1 : undefined }),
      billingNotes: (q: ListQuery & { status?: BillingStatus; customer_id?: number } = {}) =>
        client.get<Page<BillingNote>>('/accounting/billing-notes', q),
      billingNote: (id: number) => client.get<One<BillingNote>>(`/accounting/billing-notes/${id}`),
      billingPdf: (id: number) => client.download(`/accounting/billing-notes/${id}/pdf`),
      /** Downloads a pdf_url / signed_pdf_url the server handed out (only the known PDF paths, always on our own server). */
      pdfFromUrl: (url: string): Promise<Download> => {
        const target = pdfPath(url);
        if (!target) return Promise.reject(new ApiError({ status: 404, code: 'pdf_not_available' }));
        return client.download(target.path, target.query);
      },
    },
  };
}

export type Api = ReturnType<typeof createApi>;

/**
 * multipart/form-data for a check: answers[12][answer]=OK, answers[12][remark]=…, plus "image".
 */
export function checkFormData(input: CheckInput, photo: UploadFile): FormData {
  const form = new FormData();
  form.append('machine_status', input.machine_status);
  if (input.machine_note) form.append('machine_note', input.machine_note);
  for (const [id, a] of Object.entries(input.answers)) {
    form.append(`answers[${id}][answer]`, a.answer);
    if (a.remark) form.append(`answers[${id}][remark]`, a.remark);
  }
  if ('blob' in photo) {
    form.append('image', photo.blob, photo.name);
  } else {
    // React Native's FormData takes {uri, name, type} for files.
    form.append('image', photo as unknown as Blob);
  }
  return form;
}

/**
 * pdf_url → API path. The host in the URL is ignored on purpose: the token is only ever sent
 * to the server the app is configured for, and only for the known PDF endpoints.
 */
export function pdfPath(url: string): { path: string; query?: Query } | null {
  const m = /\/api\/v1(\/accounting\/(?:inets\/\d+\/pdf\/(?:388|t01|81)|billing-notes\/\d+\/pdf))(\?signed=1)?$/.exec(url.trim());
  if (!m) return null;
  return { path: m[1]!, query: m[2] ? { signed: 1 } : undefined };
}
