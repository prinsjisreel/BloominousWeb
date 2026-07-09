-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Apr 12, 2026 at 06:51 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `bloomshop_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `freshness_analysis`
--

CREATE TABLE `freshness_analysis` (
  `id` int(11) NOT NULL,
  `product_name` varchar(255) DEFAULT NULL,
  `batch_code` varchar(100) DEFAULT NULL,
  `freshness_score` int(11) DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `ai_recommendation` text DEFAULT NULL,
  `scanned_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `total_price` decimal(10,2) DEFAULT NULL,
  `delivery_address` text NOT NULL,
  `contact_number` varchar(20) NOT NULL,
  `payment_method` varchar(50) NOT NULL,
  `status` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `orders`
--

INSERT INTO `orders` (`id`, `user_id`, `total_price`, `delivery_address`, `contact_number`, `payment_method`, `status`, `created_at`) VALUES
(7, 58, 500.00, 'Saog', '09353148940', 'COD', 'Completed', '2026-04-05 11:55:45'),
(8, 58, 500.00, 'mRILAO', '09353148940', 'COD', 'Completed', '2026-04-05 11:56:54'),
(10, NULL, 500.00, 'ffergfdggfhgghhgjhhmn', '09353148940', 'COD', 'Cancelled', '2026-04-06 09:10:22'),
(11, NULL, 500.00, 'loma de gato', '09353148940', 'COD', 'On Delivery', '2026-04-08 00:26:35'),
(13, 63, 500.00, 'jdjhdjdfjfdj', '09353148940', 'GCash', 'Completed', '2026-04-10 00:57:13'),
(14, 63, 500.00, 'Lias st', '09353148940', 'GCash', 'Completed', '2026-04-11 02:56:01'),
(15, 63, 500.00, 'Lias st', '09353148940', 'GCash', 'Completed', '2026-04-11 02:56:14'),
(17, 63, 500.00, 'ggfdfgh', '09353148940', 'COD', 'Completed', '2026-04-11 04:32:56'),
(18, 63, 350.00, 'grythfgh', '09353148940', 'COD', 'Completed', '2026-04-11 04:43:23'),
(19, 63, 500.00, 'dsgdfgdfgfdg', '09353148940', 'GCash', 'Completed', '2026-04-11 07:35:34'),
(20, 63, 500.00, 'dsgdfgdfgfdg', '09353148940', 'GCash', 'Completed', '2026-04-11 07:50:12'),
(21, 63, 1000.00, 'andres', '09353148940', 'GCash', 'Completed', '2026-04-11 08:05:37'),
(22, 63, 1000.00, 'andres', '09353148940', 'GCash', 'Completed', '2026-04-11 08:11:54'),
(23, 63, 500.00, 'jnjokni', '09353148940', 'COD', 'Completed', '2026-04-12 02:19:49'),
(24, 63, 1000.00, 'ftgdxrfxed', '09353148940', 'GCash', 'Completed', '2026-04-12 02:20:55'),
(25, 63, 1000.00, 'dssc', '09353148940', 'GCash', 'Completed', '2026-04-12 02:35:05'),
(26, 63, 1000.00, 'dssc', '09353148940', 'GCash', 'Completed', '2026-04-12 02:36:45'),
(27, 63, 1000.00, 'rgfghgh', '09353148940', 'COD', 'Completed', '2026-04-12 02:37:01'),
(28, 63, 500.00, 'ddsfdvsvf', '09353148940', 'GCash', 'Completed', '2026-04-12 02:37:29'),
(29, 63, 500.00, 'ggfhfgh', '09353148940', 'GCash', 'Pending Payment', '2026-04-12 02:44:39'),
(30, 63, 500.00, 'fgnfgnghn', '09353148940', 'GCash', 'Pending Payment', '2026-04-12 02:51:11'),
(31, 63, 500.00, 'gergdfgfdgfd', '09353148940', 'COD', 'Pending', '2026-04-12 02:53:33'),
(32, 63, 500.00, 'fgfgnfbnnvnv', '09353148940', 'GCash', 'Pending Payment', '2026-04-12 02:53:55'),
(33, 63, 500.00, 'fgfgnfbnnvnv', '09353148940', 'GCash', 'Pending Payment', '2026-04-12 03:01:25'),
(34, 63, 500.00, 'fgfgnfbnnvnv', '09353148940', 'GCash', 'Pending Payment', '2026-04-12 03:05:28');

-- --------------------------------------------------------

--
-- Table structure for table `order_items`
--

CREATE TABLE `order_items` (
  `id` int(11) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `product_name` varchar(255) DEFAULT NULL,
  `quantity` int(11) DEFAULT NULL,
  `price_at_purchase` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `order_items`
--

INSERT INTO `order_items` (`id`, `order_id`, `product_id`, `product_name`, `quantity`, `price_at_purchase`) VALUES
(59, 7, 19, NULL, 1, 500.00),
(60, 8, 19, NULL, 1, 500.00),
(62, 10, 22, NULL, 1, 500.00),
(63, 11, 22, NULL, 1, 500.00),
(65, 13, 22, NULL, 1, 500.00),
(66, 14, 22, NULL, 1, 500.00),
(67, 15, 22, NULL, 1, 500.00),
(69, 17, 22, NULL, 1, 500.00),
(70, 18, 20, NULL, 1, 350.00),
(71, 19, 22, NULL, 1, 500.00),
(72, 20, 22, NULL, 1, 500.00),
(73, 21, 22, NULL, 2, 500.00),
(74, 22, 22, NULL, 2, 500.00),
(75, 23, 22, NULL, 1, 500.00),
(76, 24, 22, NULL, 2, 500.00),
(77, 25, 22, NULL, 2, 500.00),
(78, 26, 22, NULL, 2, 500.00),
(79, 27, 22, NULL, 2, 500.00),
(80, 28, 19, NULL, 1, 500.00),
(81, 29, 22, NULL, 1, 500.00),
(82, 30, 22, NULL, 1, 500.00),
(83, 31, 22, NULL, 1, 500.00),
(84, 32, 22, NULL, 1, 500.00),
(85, 33, 22, NULL, 1, 500.00),
(86, 34, 22, NULL, 1, 500.00);

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int(11) NOT NULL,
  `product_name` varchar(100) DEFAULT NULL,
  `barcode` varchar(50) DEFAULT NULL,
  `category` varchar(50) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `stock_quantity` int(11) DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `product_name`, `barcode`, `category`, `price`, `stock_quantity`, `image`, `created_at`) VALUES
(19, 'Red Roses', 'BLM-924488', 'Bouquet', 500.00, 99, '1775380866_69d229828f2ab.jpg', '2026-04-05 09:21:06'),
(20, 'Novellina', 'BLM-443627', 'Wines', 350.00, 99, '1775392223_69d255df932e5.jpg', '2026-04-05 12:30:23'),
(22, 'Pink tulips', 'BLM-242755', 'Bouquet', 500.00, 68, '1775392308_69d25634b0a5b.jpg', '2026-04-05 12:31:48');

-- --------------------------------------------------------

--
-- Table structure for table `promos`
--

CREATE TABLE `promos` (
  `id` int(11) NOT NULL,
  `promo_code` varchar(50) NOT NULL,
  `discount_percentage` int(3) NOT NULL,
  `expiry_date` date NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `shop_settings`
--

CREATE TABLE `shop_settings` (
  `id` int(11) NOT NULL,
  `shop_name` varchar(255) DEFAULT 'Bloominous Flower Shop',
  `contact_number` varchar(50) DEFAULT '09123456789',
  `email_address` varchar(255) DEFAULT 'info@bloominous.com',
  `shop_address` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `shop_settings`
--

INSERT INTO `shop_settings` (`id`, `shop_name`, `contact_number`, `email_address`, `shop_address`, `created_at`) VALUES
(1, 'Bloominous Flower Shop', '09123456789', 'admin@bloominous.com', 'Marilao, Bulacan', '2026-04-11 04:54:40');

-- --------------------------------------------------------

--
-- Table structure for table `spoilage`
--

CREATE TABLE `spoilage` (
  `id` int(11) NOT NULL,
  `flower_name` varchar(255) NOT NULL,
  `quantity` int(11) NOT NULL,
  `loss_amount` decimal(10,2) NOT NULL,
  `reason` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL,
  `supplier_name` varchar(100) NOT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `suppliers`
--

INSERT INTO `suppliers` (`id`, `supplier_name`, `contact_person`, `phone`, `address`, `created_at`) VALUES
(3, 'UNO COMPANY', 'Joseph Alvarado', '09264550919', 'st lukes marilao bulacan', '2026-04-07 12:18:34');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','customer') DEFAULT 'customer',
  `status` varchar(20) DEFAULT 'Active',
  `last_login` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `points` int(11) DEFAULT 0,
  `total_spend` decimal(10,2) DEFAULT 0.00,
  `otp_code` varchar(6) DEFAULT NULL,
  `otp_expiry` datetime DEFAULT NULL,
  `is_verified` tinyint(1) DEFAULT 0,
  `email` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `role`, `status`, `last_login`, `created_at`, `points`, `total_spend`, `otp_code`, `otp_expiry`, `is_verified`, `email`) VALUES
(57, 'Julius Gumasing', '$2y$10$i8ldqxqooQ6L7/xNs1GK8uZCipgTiWpqG26eYPlflHEau1yyC7HaS', 'admin', 'Active', NULL, '2026-04-01 13:06:46', 0, 0.00, NULL, NULL, 1, 'luckyboyph18@gmail.com'),
(58, 'Prins Galang', '$2y$10$rSUVhZ4Gle1IoZSHRbItAOMIDmS5qbzdUfIsyO7Gtd4tSAJMitzQW', 'customer', 'Active', NULL, '2026-04-01 13:24:32', 0, 0.00, NULL, NULL, 1, 'galangprincejezreel.pdm@gmail.com'),
(63, 'John Carl Andres', '$2y$10$IIAaMfUVnmNPLwkaW7OCJeRR..Du3OGHlcyvv9A9uzcDjuiINosl.', 'customer', 'Active', NULL, '2026-04-08 00:28:54', 0, 2850.00, NULL, NULL, 1, 'johncarlandres75@gmail.com'),
(64, 'Aina Dizon', '$2y$10$kISi7Dnd0U.HHua5VnRVduKFPB7fR/KurMMXRJE5OJwbqQ4U.mW3K', 'customer', 'Active', NULL, '2026-04-09 07:50:08', 0, 0.00, NULL, NULL, 1, 'dizonaina74@gmail.com');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `freshness_analysis`
--
ALTER TABLE `freshness_analysis`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_user_order` (`user_id`);

--
-- Indexes for table `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `fk_product_order_item` (`product_id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `barcode` (`barcode`);

--
-- Indexes for table `promos`
--
ALTER TABLE `promos`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `shop_settings`
--
ALTER TABLE `shop_settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `spoilage`
--
ALTER TABLE `spoilage`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`id`);

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
-- AUTO_INCREMENT for table `freshness_analysis`
--
ALTER TABLE `freshness_analysis`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=87;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `promos`
--
ALTER TABLE `promos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `shop_settings`
--
ALTER TABLE `shop_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `spoilage`
--
ALTER TABLE `spoilage`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=66;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `fk_user_order` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `order_items`
--
ALTER TABLE `order_items`
  ADD CONSTRAINT `fk_product_order_item` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`),
  ADD CONSTRAINT `order_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
