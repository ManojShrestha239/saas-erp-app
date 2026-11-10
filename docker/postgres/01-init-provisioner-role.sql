-- Dedicated non-superuser role for tenant database provisioning (Least Privilege principle)
DO $$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_catalog.pg_roles WHERE rolname = 'nexuserp_provisioner') THEN
        CREATE ROLE nexuserp_provisioner WITH
            LOGIN
            CREATEDB
            NOSUPERUSER
            NOCREATEROLE
            NOREPLICATION
            PASSWORD 'provisioner_secret';
    END IF;
END
$$;
