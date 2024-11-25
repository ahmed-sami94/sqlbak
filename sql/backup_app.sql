-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: mariadb
-- Generation Time: 25 نوفمبر 2024 الساعة 17:20
-- إصدار الخادم: 11.4.2-MariaDB-ubu2404
-- PHP Version: 8.2.8

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `backup_app`
--

-- --------------------------------------------------------

--
-- بنية الجدول `backups`
--

CREATE TABLE `backups` (
  `id` int(11) NOT NULL,
  `database_id` int(11) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `note` text DEFAULT NULL,
  `type` enum('manual','automatic') NOT NULL DEFAULT 'manual'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `databases`
--

CREATE TABLE `databases` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `host` varchar(50) NOT NULL,
  `port` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

--
-- إرجاع أو استيراد بيانات الجدول `databases`
--

INSERT INTO `databases` (`id`, `name`, `host`, `port`, `username`, `password`) VALUES
(12, 'xx', '11.11.11.11', 3306, '11', '11');

-- --------------------------------------------------------

--
-- بنية الجدول `ftp_config`
--

CREATE TABLE `ftp_config` (
  `id` int(11) NOT NULL,
  `server_ip` varchar(255) NOT NULL,
  `username` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `port` int(11) NOT NULL,
  `remote_dir_path` varchar(255) NOT NULL,
  `last_sync_time` timestamp NULL DEFAULT NULL,
  `last_synced_files` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

--
-- إرجاع أو استيراد بيانات الجدول `ftp_config`
--

INSERT INTO `ftp_config` (`id`, `server_ip`, `username`, `password`, `port`, `remote_dir_path`, `last_sync_time`, `last_synced_files`) VALUES
(2, '11.11.11.11', 'admin', 'admin', 21, '/backup', NULL, NULL);

-- --------------------------------------------------------

--
-- بنية الجدول `history`
--

CREATE TABLE `history` (
  `id` int(11) NOT NULL,
  `database_id` int(11) NOT NULL,
  `status` enum('success','failed') NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `owncloud_config`
--

CREATE TABLE `owncloud_config` (
  `id` int(11) NOT NULL,
  `owncloud_url` varchar(255) NOT NULL,
  `username` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `local_dir_path` varchar(255) NOT NULL,
  `remote_dir_path` varchar(255) NOT NULL,
  `last_upload_time` datetime DEFAULT NULL,
  `last_uploaded_files` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

--
-- إرجاع أو استيراد بيانات الجدول `owncloud_config`
--

INSERT INTO `owncloud_config` (`id`, `owncloud_url`, `username`, `password`, `local_dir_path`, `remote_dir_path`, `last_upload_time`, `last_uploaded_files`) VALUES
(1, '11.11.11.1/remote.php/webdav', 'admin', 'xxxx', '/var/www/html/sqlbak/backups', 'xxx', NULL, NULL);

-- --------------------------------------------------------

--
-- بنية الجدول `schedules`
--

CREATE TABLE `schedules` (
  `id` int(11) NOT NULL,
  `database_id` int(11) NOT NULL,
  `period` varchar(50) NOT NULL,
  `frequency` int(11) NOT NULL,
  `last_run` timestamp NULL DEFAULT NULL,
  `time` time DEFAULT NULL,
  `day_of_month` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

--
-- إرجاع أو استيراد بيانات الجدول `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `email`) VALUES
(1, 'admin', '8c6976e5b5410415bde908bd4dee15dfb167a9c873fc4bb8a81f6f2ab448a918', 'info@admin.com');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `backups`
--
ALTER TABLE `backups`
  ADD PRIMARY KEY (`id`),
  ADD KEY `database_id` (`database_id`);

--
-- Indexes for table `databases`
--
ALTER TABLE `databases`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `ftp_config`
--
ALTER TABLE `ftp_config`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `history`
--
ALTER TABLE `history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `database_id` (`database_id`);

--
-- Indexes for table `owncloud_config`
--
ALTER TABLE `owncloud_config`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `schedules`
--
ALTER TABLE `schedules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `database_id` (`database_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `backups`
--
ALTER TABLE `backups`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `databases`
--
ALTER TABLE `databases`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `ftp_config`
--
ALTER TABLE `ftp_config`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `history`
--
ALTER TABLE `history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `owncloud_config`
--
ALTER TABLE `owncloud_config`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `schedules`
--
ALTER TABLE `schedules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- قيود الجداول المُلقاة.
--

--
-- قيود الجداول `backups`
--
ALTER TABLE `backups`
  ADD CONSTRAINT `backups_ibfk_1` FOREIGN KEY (`database_id`) REFERENCES `databases` (`id`);

--
-- قيود الجداول `history`
--
ALTER TABLE `history`
  ADD CONSTRAINT `history_ibfk_1` FOREIGN KEY (`database_id`) REFERENCES `databases` (`id`);

--
-- قيود الجداول `schedules`
--
ALTER TABLE `schedules`
  ADD CONSTRAINT `schedules_ibfk_1` FOREIGN KEY (`database_id`) REFERENCES `databases` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
