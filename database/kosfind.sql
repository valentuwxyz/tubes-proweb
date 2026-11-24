-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 23, 2026 at 05:52 PM
-- Server version: 10.11.16-MariaDB
-- PHP Version: 8.5.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `kosfind`
--

-- --------------------------------------------------------

--
-- Table structure for table `bookings`
--

CREATE TABLE `bookings` (
  `id` int(10) UNSIGNED NOT NULL,
  `kos_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `tenant_name` varchar(120) NOT NULL,
  `tenant_phone` varchar(25) NOT NULL,
  `duration_months` int(11) NOT NULL DEFAULT 1,
  `estimated_total` bigint(20) NOT NULL DEFAULT 0,
  `checkin_date` date DEFAULT NULL,
  `status` enum('pending','confirmed','cancelled') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `hidden_by_owner` tinyint(1) NOT NULL DEFAULT 0,
  `hidden_by_user` tinyint(1) NOT NULL DEFAULT 0,
  `hidden_by_admin` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `bookings`
--

INSERT INTO `bookings` (`id`, `kos_id`, `user_id`, `tenant_name`, `tenant_phone`, `duration_months`, `estimated_total`, `checkin_date`, `status`, `created_at`, `hidden_by_owner`, `hidden_by_user`, `hidden_by_admin`) VALUES
(13, 12, 11, 'juan ganteng', '0882017206586', 1, 700000, '2026-11-22', 'confirmed', '2026-11-22 21:13:45', 1, 1, 1),
(14, 9, 11, 'juan ganteng', '0882017206586', 1, 2500000, '2026-11-23', 'pending', '2026-11-23 12:57:40', 0, 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `kos`
--

CREATE TABLE `kos` (
  `id` int(10) UNSIGNED NOT NULL,
  `owner_id` int(10) UNSIGNED DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `type` enum('Putri','Putra','Campur') NOT NULL,
  `price` int(10) UNSIGNED NOT NULL,
  `distance` varchar(150) NOT NULL,
  `address` varchar(255) NOT NULL,
  `campus_name` varchar(120) DEFAULT NULL,
  `rating` decimal(2,1) NOT NULL DEFAULT 4.8,
  `size` varchar(60) NOT NULL,
  `electricity` varchar(120) NOT NULL,
  `pdam` varchar(100) DEFAULT 'Sudah Termasuk Biaya Kos',
  `security` varchar(100) DEFAULT 'CCTV 24 Jam',
  `description` text NOT NULL,
  `rules` text DEFAULT NULL,
  `facilities` text NOT NULL,
  `map_query` varchar(150) NOT NULL,
  `main_image` varchar(255) NOT NULL,
  `gallery_images` text DEFAULT NULL,
  `owner_whatsapp` varchar(25) NOT NULL DEFAULT '6281260007890',
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'approved',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `available_rooms` int(10) UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `kos`
--

INSERT INTO `kos` (`id`, `owner_id`, `name`, `type`, `price`, `distance`, `address`, `campus_name`, `rating`, `size`, `electricity`, `pdam`, `security`, `description`, `rules`, `facilities`, `map_query`, `main_image`, `gallery_images`, `owner_whatsapp`, `status`, `created_at`, `available_rooms`) VALUES
(9, 6, 'kost asoy', 'Campur', 2500000, '1km dari usu', 'mariska kost medan', 'USU', 2.0, '3 x 4 Meter', 'Token Sendiri', 'Sudah Termasuk Biaya Kos', 'CCTV 24 Jam', 'mantap boyy', 'Tidak di per untukan PASUTRI\r\nTidak di perkenakan membawa  pasangan', 'AC, WIFI, MESIN CUCI, KOMPOR', 'mariska kost medan', 'uploads/kos/kos_9_1795248575_169c37.webp', NULL, '082171892045', 'approved', '2026-11-20 16:38:34', 10),
(10, 6, 'Rumah Kost SAPO', 'Campur', 900000, '400 meter dari usu', 'Rumah kost SAPO', 'USU', 4.8, '3 x 4 Meter', 'Token Sendiri', 'Sudah Termasuk Biaya Kos', 'CCTV 24 Jam', 'Bersih dan di jamin Nyaman', 'Tidak untuk PASUTRI\r\nTamu wajib lapor', 'AC, WIFI, MESIN CUCI, KOMPOR,KAMAR MANDI DALAM', 'Rumah kost SAPO', 'uploads/kos/kos_1795249427_31047bab.jpg', NULL, '6285142198183', 'approved', '2026-11-21 08:23:47', 3),
(11, 6, 'Mansyur Asri Kost', 'Putra', 1000000, '300 METER DARI USU', 'Mansyur Asri Kost', 'USU', 4.8, '3 x 4 Meter', 'Token Sendiri', 'Sudah Termasuk Biaya Kos', 'CCTV 24 Jam', 'Murah dan Nyaman', 'TIDAK UNTUK PASUTRI\r\nTAMU WAIJIB LAPOR', 'AC,WIFI,KAMAR MANDI DALAM,PARKIRAN', 'Mansyur Asri Kost', 'uploads/kos/kos_1795250185_536a35e2.webp', NULL, '6285142198183', 'approved', '2026-11-21 08:36:25', 5),
(12, 6, 'MAYADANA KOST', 'Putri', 700000, '1km dari usu', '', 'USU', 4.8, '3 x 4 Meter', 'Token Sendiri', 'Sudah Termasuk Biaya Kos', 'CCTV 24 Jam', 'NYAMAN', 'TIDAK UNTUK PASUTRI\r\nTAMU WAJIB LAPOR', 'AC, WIFI, MESIN CUCI, KOMPOR,KAMAR MANDI DALAM', 'MAYADANA KOST', 'uploads/kos/kos_12_1795251443_0_6aa2.webp', '[\"uploads/kos/kos_12_1795251443_0_6aa2.webp\"]', '6285142198183', 'approved', '2026-11-21 08:39:09', 1),
(13, 6, 'Kos Dolfi', 'Putra', 1000000, '550m dari kampus', '', 'UMSU', 4.8, '3 x 4 Meter', 'Sudah Termasuk Biaya Kos', 'Sudah Termasuk Biaya Kos', 'CCTV 24 Jam', 'Hunian nyaman di area kampus UMSU.', '', 'AC, WIFI, MESIN CUCI, KOMPOR,KAMAR MANDI DALAM', 'Kos Dolfi, Jl. Bono No.68/67, Glugur Darat I, Kec. Medan Tim., Kota Medan, Sumatera Utara 20238, Indonesia', 'uploads/kos/kos_1795372453_7f82a126.jpg', NULL, '0882017206586', 'approved', '2026-11-22 18:34:13', 5);

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(10) UNSIGNED NOT NULL,
  `recipient_user_id` int(10) UNSIGNED DEFAULT NULL,
  `recipient_role` varchar(20) DEFAULT NULL,
  `booking_id` int(10) UNSIGNED DEFAULT NULL,
  `kos_id` int(10) UNSIGNED DEFAULT NULL,
  `type` varchar(50) NOT NULL DEFAULT 'info',
  `title` varchar(150) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `recipient_user_id`, `recipient_role`, `booking_id`, `kos_id`, `type`, `title`, `message`, `is_read`, `created_at`) VALUES
(8, 6, 'pemilik', 13, 12, 'new_booking', 'Pesanan Kos Baru Masuk', 'juan ganteng memesan kos \"MAYADANA KOST\" selama 1 bulan (Rp 700.000).', 1, '2026-11-23 04:13:45'),
(11, 6, 'pemilik', 14, 9, 'new_booking', 'Pesanan Kos Baru Masuk', 'juan ganteng memesan kos \"kost asoy\" selama 1 bulan (Rp 2.500.000).', 0, '2026-11-23 19:57:40'),
(12, 1, 'admin', 14, 9, 'new_booking', 'Pesanan Kos Baru Masuk', 'juan ganteng memesan kos \"kost asoy\" selama 1 bulan (Rp 2.500.000).', 0, '2026-11-23 19:57:40');

-- --------------------------------------------------------

--
-- Table structure for table `reviews`
--

CREATE TABLE `reviews` (
  `id` int(10) UNSIGNED NOT NULL,
  `kos_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `reviewer_name` varchar(120) NOT NULL,
  `rating` tinyint(3) UNSIGNED NOT NULL,
  `comment` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `reviews`
--

INSERT INTO `reviews` (`id`, `kos_id`, `user_id`, `reviewer_name`, `rating`, `comment`, `created_at`) VALUES
(1, 1, 5, 'Juan akmal rahadi', 3, 'masih banyak kurang nya', '2026-11-20 16:27:20'),
(2, 1, 5, 'Juan akmal rahadi', 4, 'mantap', '2026-11-20 16:27:48'),
(3, 9, 11, 'juan ganteng', 2, 'kemahalan', '2026-11-20 17:25:47');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(120) NOT NULL,
  `username` varchar(50) DEFAULT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','pemilik','user') NOT NULL DEFAULT 'user',
  `phone` varchar(25) DEFAULT NULL,
  `avatar` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `username`, `email`, `password`, `role`, `phone`, `avatar`, `created_at`) VALUES
(1, 'Admin KosFind', NULL, 'admin@kosfind.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', '6281260007890', NULL, '2026-11-19 17:17:38'),
(2, 'Budi Pemilik Kos', NULL, 'pemilik@kosfind.usu', '$2y$12$Pf2JbU5EuRYaIvkauCSMze6HKvYJKTqxsM9OdgogidhkV5ypNUGG', 'pemilik', '6281260001111', NULL, '2026-11-19 17:17:38'),
(3, 'Mahasiswa USU', NULL, 'user@kosfind.usu', '$2y$12$Pf2JbU5EuRYaIvkauCSMze6HKvYJKTqxsM9OdgogidhkV5ypNUGG', 'user', '6281260002222', NULL, '2026-11-19 17:17:38'),
(4, 'Juan akmal rahadi', NULL, 'akmalrahadij@gmail.com', '$2y$12$ioL42u79SPM8TqL7czxTSOwuFfn7EEhhBfThOsrPG8Ge/EfWraer.', 'user', '0882017206586', NULL, '2026-11-19 17:23:22'),
(5, 'Juan JOs jis', 'Juan Jos Jis', 'juan12@gmail.com', '$2y$12$622fvVAl2Je3mKoNZrcVd.M9xYqsFh9E.wLDm/aoIkrQv/bq.50V2', 'user', '0882017206586', 'uploads/avatars/avatar_5_1795251859_f607cf.jpg', '2026-11-19 17:47:02'),
(6, 'brad pit', NULL, 'bradpit@gamil.com', '$2y$12$Lb9eCSFzNaDxmODS7PPrwerdGSzP/irZsOMODUBpHA8bcXetfOJi6', 'pemilik', '0882017206586', 'uploads/avatars/avatar_6_1795253223_59685b.jpg', '2026-11-19 18:01:25'),
(8, 'aura', NULL, 'aura@gmail.com', '$2y$12$ZgYvfLBeHbAdiMQoqiaK7.AN3ZbdvgQx.jMUYT2Y8A7SHZVfxZtRG', 'user', '1221333312121', NULL, '2026-11-20 02:13:59'),
(9, 'AURA', NULL, 'aurasyiva@gmail.com', '$2y$12$/6GbZ2M/RnDpOQ6L71Zoq.7JGv3kJgm0Z9A6g3f5vLrSDGxUQU5ze', 'pemilik', '22111222', NULL, '2026-11-20 02:24:08'),
(10, 'AURA', NULL, 'aura12@gmail.com', '$2y$12$uaLwtDTwMDnEy2uQ6T6Cl.8rpDSvdyXaUWWyMWSB7ELbCzxd6XvJu', 'pemilik', '33333', NULL, '2026-11-20 02:25:32'),
(11, 'juan ganteng', NULL, 'juan77@gmail.com', '$2y$12$ypRfq9Za7D1B9QqeKOuTAOhgA3UXZ2uRIZOLJ.GDtesfo3cdkNSvq', 'user', '0882017206586', NULL, '2026-11-20 17:07:55');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `bookings`
--
ALTER TABLE `bookings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_bookings_kos` (`kos_id`),
  ADD KEY `idx_bookings_user` (`user_id`);

--
-- Indexes for table `kos`
--
ALTER TABLE `kos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_kos_owner` (`owner_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_recipient_user` (`recipient_user_id`,`is_read`),
  ADD KEY `idx_recipient_role` (`recipient_role`,`is_read`);

--
-- Indexes for table `reviews`
--
ALTER TABLE `reviews`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_reviews_kos` (`kos_id`),
  ADD KEY `idx_reviews_user` (`user_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `bookings`
--
ALTER TABLE `bookings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `kos`
--
ALTER TABLE `kos`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `reviews`
--
ALTER TABLE `reviews`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
