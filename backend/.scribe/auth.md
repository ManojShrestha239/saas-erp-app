# Authenticating requests

To authenticate requests, include an **`Authorization`** header with the value **`"Bearer Bearer {YOUR_API_TOKEN}"`**.

All authenticated endpoints are marked with a `requires authentication` badge in the documentation below.

Requests require an authenticated tenant session cookie or a Bearer token in the `Authorization` header. Tenant requests must target the tenant subdomain (e.g. `http://{tenant}.nexuserp.test`).
