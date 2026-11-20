-- Automatically create the central and test databases on initial MySQL startup
CREATE DATABASE IF NOT EXISTS `mysql_central` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS `mysql_testing` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Ensure root has proper permissions across all databases
GRANT ALL PRIVILEGES ON *.* TO 'root'@'%' WITH GRANT OPTION;
FLUSH PRIVILEGES;
