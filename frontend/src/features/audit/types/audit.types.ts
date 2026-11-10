export interface AuditCauser {
  id: string | number;
  name: string;
  email?: string | null;
}

export interface AuditChanges {
  old: Record<string, unknown> | null;
  attributes: Record<string, unknown> | null;
}

export interface AuditLog {
  id: number;
  log_name: string | null;
  description: string;
  subject_type: string | null;
  subject_id: string | null;
  event: string | null;
  causer_type: string | null;
  causer_id: string | null;
  causer: AuditCauser | null;
  properties: Record<string, unknown>;
  changes: AuditChanges;
  ip_address: string | null;
  user_agent: string | null;
  created_at: string | null;
}

export interface AuditFilterParams {
  search?: string;
  event?: string;
  subject_type?: string;
  causer_id?: string;
  from?: string;
  to?: string;
  page?: number;
  per_page?: number;
  sort_by?: string;
  sort_direction?: 'asc' | 'desc';
}

export interface GlobalAuditLog {
  id: number;
  tenant_id: string | null;
  user_id: string | null;
  event: string;
  auditable_type: string | null;
  auditable_id: string | null;
  old_values: Record<string, unknown> | null;
  new_values: Record<string, unknown> | null;
  ip_address: string | null;
  user_agent: string | null;
  created_at: string | null;
}

export interface GlobalAuditFilterParams {
  search?: string;
  event?: string;
  tenant_id?: string;
  auditable_type?: string;
  from?: string;
  to?: string;
  page?: number;
  per_page?: number;
  sort_by?: string;
  sort_direction?: 'asc' | 'desc';
}
