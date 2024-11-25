#!/bin/bash

# Input parameters
local_dir_path=$1
remote_dir_path=$2
owncloud_url=$3
username=$4
password=$5

# Log file
log_file="/path/to/upload_script.log"
echo "Starting upload script at $(date)" >> $log_file

# Ensure paths are trimmed and handle quotes
local_dir_path=$(echo "$local_dir_path" | sed 's/\/$//')
remote_dir_path=$(echo "$remote_dir_path" | sed 's/\/$//')

# Ensure the local directory exists
if [ ! -d "$local_dir_path" ]; then
    echo "Error: Local directory $local_dir_path does not exist." >> $log_file
    exit 1
fi

# Find all files in the local directory and upload them
find "$local_dir_path" -type f | while read file; do
    filename=$(basename "$file")
    remote_file_path="$remote_dir_path/$filename"
    
    # Upload the file
    echo "Uploading $file to $remote_file_path" >> $log_file
    curl -u "$username:$password" -T "$file" "http://$owncloud_url/remote.php/webdav/$remote_file_path" >> $log_file 2>&1

    # Check if curl command was successful
    if [ $? -ne 0 ]; then
        echo "Error: Failed to upload $file." >> $log_file
    else
        echo "Uploaded $file to $remote_file_path." >> $log_file
    fi
done
echo "Upload script completed at $(date)" >> $log_file

