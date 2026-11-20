-- Dedicated non-superuser user for tenant database provisioning (Least Privilege principle)
CREATE USER IF NOT EXISTS 'nexuserp_provisioner'@'%' IDENTIFIED BY 'provisioner_secret';

-- Grant privileges to create, alter, and manage tenant databases without administrative SUPER/RELOAD privileges
GRANT CREATE, DROP, ALTER, SELECT, INSERT, UPDATE, DELETE, INDEX, REFERENCES, LOCK TABLES ON *.* TO 'nexuserp_provisioner'@'%';
FLUSH PRIVILEGES;
