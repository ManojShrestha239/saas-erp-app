export interface AuthUser {
  id: string;
  name: string;
  email: string;
  status: string;
  email_verified: boolean;
  roles: string[];
  permissions: string[];
}

export interface SharedProps {
  auth: {
    user: AuthUser | null;
  };
  tenant: {
    id: string;
    slug: string;
  } | null;
  flash: {
    success: string | null;
    error: string | null;
  };
  [key: string]: unknown;
}
