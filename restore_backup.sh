#!/bin/bash

# Variables
MYSQL_USER="backup"
MYSQL_PASS="backup"
MYSQL_DB="backup_app"
ROOT_USER="root"
ROOT_PASS="Alaman12#$"
BACKUP_DIR="/var/www/html/sqlbak/backups/"

# Check if backup file is provided
if [ -z "$1" ]; then
    echo "Usage: $0 <backup_filename>"
    exit 1
fi

BACKUP_FILE="$1"

# Extract database details from backup file name (Assuming format: backup_DBNAME_YYYYMMDD_HHMMSS.sql)
DATABASE_NAME=$(echo "$BACKUP_FILE" | sed -E 's/backup_(.*)_.*\.sql/\1/')

# Restore the database
if [ -f "$BACKUP_DIR$BACKUP_FILE" ]; then
    echo "Restoring database $DATABASE_NAME from $BACKUP_FILE..."
    mysql -u "$ROOT_USER" -p"$ROOT_PASS" "$DATABASE_NAME" < "$BACKUP_DIR$BACKUP_FILE"

    if [ $? -eq 0 ]; then
        echo "Restoration successful."
    else
        echo "Restoration failed."
    fi
else
    echo "Backup file $BACKUP_FILE not found in $BACKUP_DIR."
fi

