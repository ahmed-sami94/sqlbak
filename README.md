# SQLBak

**SQLBak** is a robust PHP-based solution for managing MySQL database backups. It provides a user-friendly interface to streamline backup, restore, and synchronization processes for databases. The application supports Docker and has been tested in production for 6 months.

---

## Project Overview

This project provides a web interface for managing MySQL database backups. It includes functionality for scheduling backups, performing manual backups, restoring backups, and managing users. The application also supports storing backups and managing backup history.
![image](https://github.com/user-attachments/assets/89ea1d87-3507-4ed2-aac1-1b0fa047b01f)

---

## Features

### **Dashboard**
- Displays system status and resource usage.
- Graphs to visualize the success rate of backups.

### **Backup Management**
- **List of Backups**:
  - View all backups (manual and scheduled).
  - Includes database name, backup date, comments, and actions (download, restore, or delete).
- **Manual Backup**:
  - Select databases for manual backup.
  - Add comments to identify backups.
- **Scheduled Backup**:
  - Configure automated backups with flexible schedules.
- Comments for each backup, improving organization and tracking.

### **Database Management**
- Add, modify, and delete databases.
- Support for linking multiple MySQL servers (containers or on-premises) reachable by IP or domain name.

### **Backup Synchronization**
- Configure and sync with **ownCloud** for cloud storage.
- Sync recent downloads with an FTP server.

### **Restore Functionality**
- Restore databases directly from backup files stored on the server.
- Upload a local file from your PC to restore a database.

### **User Management**
- Add, delete, and manage users with different roles and permissions.

### **Additional Enhancements**
- **OwnCloud Integration**: Sync backups to ownCloud for easy access and storage.
- **FTP Sync**: Automatically sync recent downloads to an FTP server.
- **Compatibility**:
  - The backup directory (`/var/www/html/sqlbak/backups`) can be mounted using Docker or shared via tools like NFS or Samba.

---

## Requirements

To run this project locally, you will need:
- PHP 7.4 or higher
- MySQL server
- Apache web server
- Docker (optional for containerization)

---

## Installation

### **Step 1: Clone the Repository**
```bash
git clone https://github.com/ahmed-sami94/sqlbak.git
cd sqlbak


2. Set up the MySQL database:
    ```bash
    mysql -u root -p < sql/backup_app.sql
    ```

3. Configure the project:
   - **Database Connection**: Update the database configuration in `db.php`.
   - The default credentials for logging into the application are:
     - Username: `admin`
     - Password: `admin`

4. Start the web application:
   - Make sure Apache and PHP are set up to serve the application.
   - Alternatively, use Docker to containerize the app.

## Usage

- **Accessing the Web Interface**: Open [http://localhost/index.php](http://localhost/index.php) in your browser.
- **Backup Operations**: Use `backup_scheduler.php` for scheduling backups or `manual_backup.php` for manual backups.
- **User Management**: Admin users can manage system users via `users.php`.
- **Backup Restoration**: Restore backups via `restore_backup.php`.

## Docker Setup (Optional)
If you prefer to containerize the application:
1. Refer to the `Dockerfile` and `docker-compose.yml` for setup instructions.
2. Build and run the Docker containers:
    ```bash
    docker-compose up --build
    ```

## Additional Details

### Database Schema
The project uses a MySQL database initialized with `sql/backup_app.sql`. Key tables include:
- `databases`
- `backups`
- `schedules`

### Scripts Overview

#### Backup Handling
- **`auto_backup.php`**: Handles automatic backups based on schedules.
- **`manual_backup.php`**: Allows manual triggering of backups.
- **`check_and_run_backup.php`**: Checks for pending backups and triggers them.

#### Backup Restoration
- **`restore_backup.php`**: Interface for restoring a selected backup.
- **`restore_backup.sh`**: Shell script to restore a backup from a file.

#### Scheduling
- **`backup_scheduler.php`**: Set up backup schedules for periodic backups.
- **`update_schedule.php`**: Update existing backup schedules.

#### Database Management
- **`add_database.php`**: Add new databases to be backed up.
- **`modify_database.php`**: Modify database connection details.
- **`delete_database.php`**: Remove a database from the backup system.

---

## License
This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.

---
