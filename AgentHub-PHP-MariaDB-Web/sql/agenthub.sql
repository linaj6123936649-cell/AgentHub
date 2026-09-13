-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- 主機： localhost
-- 產生時間： 2026 年 09 月 13 日 15:15
-- 伺服器版本： 10.11.11-MariaDB
-- PHP 版本： 8.2.28

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- 資料庫： `agenthub`
--

-- --------------------------------------------------------

--
-- 資料表結構 `agents`
--

CREATE TABLE `agents` (
  `name` varchar(80) NOT NULL,
  `registered_at` datetime(6) NOT NULL,
  `last_seen_at` datetime(6) NOT NULL,
  `transfer_url` varchar(500) DEFAULT NULL,
  `transfer_token` varchar(200) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- 資料表結構 `files`
--

CREATE TABLE `files` (
  `id` char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(180) NOT NULL,
  `sender` varchar(80) NOT NULL,
  `size_bytes` bigint(20) UNSIGNED NOT NULL,
  `content_type` varchar(255) NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `stored_name` varchar(240) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- 資料表結構 `messages`
--

CREATE TABLE `messages` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `sender` varchar(80) NOT NULL,
  `target` varchar(80) NOT NULL,
  `content` mediumtext NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `transfer_manifest` mediumtext DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- 資料表結構 `message_attachments`
--

CREATE TABLE `message_attachments` (
  `message_id` bigint(20) UNSIGNED NOT NULL,
  `file_id` char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `position` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- 資料表結構 `migrations`
--

CREATE TABLE `migrations` (
  `version` varchar(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `applied_at` datetime(6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- 已傾印資料表的索引
--

--
-- 資料表索引 `agents`
--
ALTER TABLE `agents`
  ADD PRIMARY KEY (`name`);

--
-- 資料表索引 `files`
--
ALTER TABLE `files`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_files_stored_name` (`stored_name`);

--
-- 資料表索引 `messages`
--
ALTER TABLE `messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_messages_target_id` (`target`,`id`),
  ADD KEY `idx_messages_created_at` (`created_at`);

--
-- 資料表索引 `message_attachments`
--
ALTER TABLE `message_attachments`
  ADD PRIMARY KEY (`message_id`,`file_id`),
  ADD KEY `fk_message_attachments_file` (`file_id`);

--
-- 資料表索引 `migrations`
--
ALTER TABLE `migrations`
  ADD PRIMARY KEY (`version`);

--
-- 在傾印的資料表使用自動遞增(AUTO_INCREMENT)
--

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `messages`
--
ALTER TABLE `messages`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 已傾印資料表的限制式
--

--
-- 資料表的限制式 `message_attachments`
--
ALTER TABLE `message_attachments`
  ADD CONSTRAINT `fk_message_attachments_file` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`),
  ADD CONSTRAINT `fk_message_attachments_message` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
