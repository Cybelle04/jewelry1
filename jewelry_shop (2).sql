-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 28, 2026 at 07:29 AM
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
-- Database: `jewelry_shop`
--

-- --------------------------------------------------------

--
-- Table structure for table `admin_preferences`
--

CREATE TABLE `admin_preferences` (
  `pref_key` varchar(64) NOT NULL,
  `pref_value` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admin_preferences`
--

INSERT INTO `admin_preferences` (`pref_key`, `pref_value`, `updated_at`) VALUES
('setAccent', '#5b5fd4', '2026-10-28 04:36:53'),
('setSidebar', 'dark', '2026-10-28 04:36:53'),
('setTheme', 'cream', '2026-10-28 04:40:29'),
('toggleAnimations', '1', '2026-10-28 04:36:53'),
('toggleCompact', '0', '2026-10-28 04:36:53'),
('toggleLowStock', '1', '2026-10-28 06:09:29'),
('toggleNewCustomer', '1', '2026-10-28 06:09:24'),
('toggleNewOrders', '1', '2026-10-28 06:09:24'),
('toggleOrderStatus', '0', '2026-10-28 06:09:24'),
('toggleWeekly', '0', '2026-10-28 06:09:24');

-- --------------------------------------------------------

--
-- Table structure for table `cart`
--

CREATE TABLE `cart` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `quantity` int(11) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `categories`
--

INSERT INTO `categories` (`id`, `name`, `description`, `image`, `created_at`) VALUES
(1, 'Ring', 'Elegant rings crafted with precision', 'cat_1793155457_b21ae5bb.jpg', '2026-10-13 04:58:02'),
(2, 'Bracelet', 'Charming bracelets to enhance your style', 'cat_1793155471_ff16a434.jpg', '2026-10-13 04:58:02'),
(3, 'Necklace', 'Beautiful necklaces for every occasion', 'cat_1793155449_9cf75ad5.jpg', '2026-10-13 04:58:02'),
(4, 'Earring', 'Stylish earrings for daily wear', 'cat_1793155440_afd503ea.jpg', '2026-10-13 04:58:02');

-- --------------------------------------------------------

--
-- Table structure for table `feedback`
--

CREATE TABLE `feedback` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `rating` tinyint(4) DEFAULT NULL,
  `status` enum('Pending','Approved','Hidden') NOT NULL DEFAULT 'Pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `layaway_payments`
--

CREATE TABLE `layaway_payments` (
  `id` int(11) NOT NULL,
  `plan_id` int(11) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `reference_no` varchar(100) DEFAULT NULL,
  `proof_image` varchar(255) DEFAULT NULL,
  `status` enum('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  `paid_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `verified_at` timestamp NULL DEFAULT NULL,
  `verified_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `layaway_payments`
--

INSERT INTO `layaway_payments` (`id`, `plan_id`, `amount`, `payment_method`, `reference_no`, `proof_image`, `status`, `paid_at`, `verified_at`, `verified_by`, `notes`) VALUES
(11, 5, 1220.00, 'GCash', '32532532523', 'uploads/layaway_proofs/proof_5_1792908413_81f89c11.jpg', 'verified', '2026-10-25 06:06:53', '2026-10-25 06:10:59', 3, ''),
(12, 5, 110.00, 'GCash', '32532532523', 'uploads/layaway_proofs/proof_5_1792908914_7c62c642.jpg', 'pending', '2026-10-25 06:15:14', NULL, NULL, '');

-- --------------------------------------------------------

--
-- Table structure for table `layaway_plans`
--

CREATE TABLE `layaway_plans` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `total_amount` decimal(12,2) NOT NULL,
  `down_payment` decimal(12,2) NOT NULL,
  `balance` decimal(12,2) NOT NULL,
  `period_days` int(11) NOT NULL DEFAULT 60,
  `status` enum('pending','approved','rejected','active','overdue','completed','cancelled') NOT NULL DEFAULT 'pending',
  `start_date` date DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `layaway_plans`
--

INSERT INTO `layaway_plans` (`id`, `user_id`, `product_id`, `total_amount`, `down_payment`, `balance`, `period_days`, `status`, `start_date`, `due_date`, `notes`, `created_at`, `updated_at`) VALUES
(5, 32, 21, 12000.00, 100.00, 10680.00, 120, 'active', '2026-10-25', '2027-02-22', NULL, '2026-10-25 06:06:23', '2026-10-25 06:10:59');

-- --------------------------------------------------------

--
-- Table structure for table `layaway_settings`
--

CREATE TABLE `layaway_settings` (
  `id` int(11) NOT NULL DEFAULT 1,
  `periods` varchar(255) NOT NULL DEFAULT '30,60,90,120',
  `min_down_payment_percent` decimal(5,2) NOT NULL DEFAULT 20.00,
  `reminder_days` varchar(100) NOT NULL DEFAULT '7,3,1',
  `overdue_grace_days` int(11) NOT NULL DEFAULT 3,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `layaway_settings`
--

INSERT INTO `layaway_settings` (`id`, `periods`, `min_down_payment_percent`, `reminder_days`, `overdue_grace_days`, `updated_at`) VALUES
(1, '30,60,90,120', 20.00, '7,3,1', 3, '2026-10-13 04:56:05');

-- --------------------------------------------------------

--
-- Table structure for table `messages`
--

CREATE TABLE `messages` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `sender_type` varchar(50) NOT NULL DEFAULT 'system',
  `subject` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `type` enum('order','layaway','payment','due','system','purchase') DEFAULT 'system',
  `title` varchar(255) NOT NULL,
  `message` text DEFAULT NULL,
  `link` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `type`, `title`, `message`, `link`, `is_read`, `created_at`, `updated_at`) VALUES
(31, 3, 'order', 'New order #000040 received', 'Cybelle Canas Dequit · ₱70,125.00 · GCASH · Shop discount 15% (-₱12,375.00) · Ref: 2433546576878 · ???? Proof uploaded', 'orders.php?status=pending&highlight=40', 1, '2026-10-24 03:15:12', '2026-10-24 11:20:38'),
(32, 30, 'order', 'Order #000040 placed', 'Thank you! Your order of ₱70,125.00 is now pending verification of your GCash payment. A 15% shop discount (₱12,375.00) was applied.', 'order-success.php?id=40', 1, '2026-10-24 03:15:12', '2026-10-24 03:15:18'),
(33, 3, 'order', 'New order #000041 received', 'Cybelle Canas Dequit · ₱16,500.00 · GCASH · Ref: 1431435547685 · ???? Proof uploaded', 'orders.php?status=pending&highlight=41', 1, '2026-10-24 07:56:58', '2026-10-24 11:20:37'),
(34, 31, 'order', 'Order #000041 placed', 'Thank you! Your order of ₱16,500.00 is now pending verification of your GCash payment.', 'order-success.php?id=41', 1, '2026-10-24 07:56:58', '2026-10-24 08:25:36'),
(35, 3, 'order', 'New order #000042 received', 'Cybelle Canas Dequit · ₱16,500.00 · COD', 'orders.php?status=pending&highlight=42', 1, '2026-10-24 08:29:49', '2026-10-24 11:20:35'),
(36, 31, 'order', 'Order #000042 placed', 'Thank you! Your order of ₱16,500.00 is now pending — please prepare cash on delivery.', 'order-success.php?id=42', 1, '2026-10-24 08:29:49', '2026-10-24 08:54:04'),
(37, 31, 'order', 'Order is being prepared — #000041', 'Your order is being prepared for shipment.', 'order-success.php?id=41', 1, '2026-10-24 09:36:23', '2026-10-24 09:36:40'),
(38, 31, 'order', 'Order delivered — #000041', 'Your order has been delivered. Thank you for your purchase!', 'order-success.php?id=41', 0, '2026-10-24 09:37:51', '2026-10-24 09:37:51'),
(39, 30, 'order', 'Order delivered — #000040', 'Your order has been delivered. Thank you for your purchase!', 'order-success.php?id=40', 1, '2026-10-24 10:12:13', '2026-10-25 02:18:31'),
(40, 30, 'order', 'Order is being prepared — #000039', 'Your order is being prepared for shipment.', 'order-success.php?id=39', 1, '2026-10-24 10:12:24', '2026-10-25 02:18:31'),
(41, 30, 'order', 'Order delivered — #000039', 'Your order has been delivered. Thank you for your purchase!', 'order-success.php?id=39', 1, '2026-10-24 10:12:30', '2026-10-25 02:18:31'),
(42, 31, 'order', 'Order delivered — #000042', 'Your order has been delivered. Thank you for your purchase!', 'order-success.php?id=42', 0, '2026-10-24 10:12:32', '2026-10-24 10:12:32'),
(43, 3, 'order', 'New order #000043 received', 'Cybelle Canas Dequit · ₱31,040.00 · GCASH · Shop discount 3% (-₱960.00) · Ref: 1233214124124 · ???? Proof uploaded', 'orders.php?status=pending&highlight=43', 0, '2026-10-25 06:02:21', '2026-10-25 06:02:21'),
(44, 32, 'order', 'Order #000043 placed', 'Thank you! Your order of ₱31,040.00 is now pending verification of your GCash payment. A 3% shop discount (₱960.00) was applied.', 'order-success.php?id=43', 1, '2026-10-25 06:02:21', '2026-10-25 06:02:26'),
(45, 32, 'layaway', 'New layaway created — #LY-0005', 'An admin has set up a layaway plan for \"Geometric Gold Ring\". Total: ₱12,000.00. Down paid: ₱100.00. Balance: ₱11,900.00. Due: Feb 22, 2027.', 'account.php', 1, '2026-10-25 06:06:23', '2026-10-25 06:06:34'),
(46, 32, 'payment', 'Payment received — #LY-0005', 'We received your payment of ₱1,220.00 for \"Geometric Gold Ring\". Remaining balance: ₱10,680.00.', 'account.php', 1, '2026-10-25 06:10:59', '2026-10-25 06:11:20'),
(47, 3, 'order', 'New order #000044 received', 'Cybelle Canas Dequit · ₱61,200.00 · COD · Shop discount 15% (-₱10,800.00)', 'orders.php?status=pending&highlight=44', 0, '2026-10-25 10:56:13', '2026-10-25 10:56:13'),
(48, 32, 'order', 'Order #000044 placed', 'Thank you! Your order of ₱61,200.00 is now pending — please prepare cash on delivery. A 15% shop discount (₱10,800.00) was applied.', 'order-success.php?id=44', 1, '2026-10-25 10:56:13', '2026-10-25 10:56:18'),
(49, 32, 'order', 'Order received — #000044', 'We have received your order and will process it shortly.', 'order-success.php?id=44', 1, '2026-10-25 10:58:13', '2026-10-25 10:58:40'),
(50, 32, 'payment', 'Payment verified — Order #000043', 'Your GCash payment for Order #000043 (₱31,040.00) has been verified. Your order is now being processed.', 'order-success.php?id=43', 1, '2026-10-25 10:58:29', '2026-10-25 10:58:40'),
(51, 3, 'order', 'New order #000045 received', 'Faye Cresell Sarsalijo · ₱18,000.00 · COD', 'orders.php?status=pending&highlight=45', 0, '2026-10-25 12:27:55', '2026-10-25 12:27:55'),
(52, 33, 'order', 'Order #000045 placed', 'Thank you! Your order of ₱18,000.00 is now pending — please prepare cash on delivery.', 'order-success.php?id=45', 1, '2026-10-25 12:27:55', '2026-10-25 13:36:10'),
(53, 3, 'order', 'New order #000046 received', 'Cybelle Canas Dequit · ₱18,000.00 · COD', 'orders.php?status=pending&highlight=46', 0, '2026-10-27 03:31:16', '2026-10-27 03:31:16'),
(54, 34, 'order', 'Order #000046 placed', 'Thank you! Your order of ₱18,000.00 is now pending — please prepare cash on delivery.', 'order-success.php?id=46', 1, '2026-10-27 03:31:16', '2026-10-27 03:31:21'),
(55, 30, 'order', 'Order placed successfully', 'Your order #000053 was received. Payment: Cash on Delivery · Total: ₱16,160.00. We\'ll update you as it moves along.', 'order-details.php?id=53', 1, '2026-10-28 02:43:06', '2026-10-28 02:43:35'),
(56, 3, 'order', 'New order received', 'Customer placed order #000053 (Cash on Delivery) — total ₱16,160.00.', '../admin/orders.php', 0, '2026-10-28 02:43:06', '2026-10-28 02:43:06'),
(57, 30, 'order', 'Order placed successfully', 'Your order #000054 was received. Payment: Cash on Delivery · Total: ₱16,160.00. We\'ll update you as it moves along.', 'order-details.php?id=54', 1, '2026-10-28 03:21:02', '2026-10-28 03:21:06'),
(58, 3, 'order', 'New order received', 'Customer placed order #000054 (Cash on Delivery) — total ₱16,160.00.', '../admin/orders.php', 0, '2026-10-28 03:21:02', '2026-10-28 03:21:02'),
(59, 30, 'order', 'Order placed successfully', 'Your order #000055 was received. Payment: Cash on Delivery · Total: ₱16,160.00. We\'ll update you as it moves along.', 'order-details.php?id=55', 1, '2026-10-28 03:33:37', '2026-10-28 03:33:41'),
(60, 3, 'order', 'New order received', 'Customer placed order #000055 (Cash on Delivery) — total ₱16,160.00.', '../admin/orders.php', 0, '2026-10-28 03:33:37', '2026-10-28 03:33:37'),
(61, 30, 'order', 'Order placed successfully', 'Your order #000056 was received. Payment: Cash on Delivery · Total: ₱16,160.00. We\'ll update you as it moves along.', 'order-details.php?id=56', 1, '2026-10-28 04:11:28', '2026-10-28 04:11:32'),
(62, 3, 'order', 'New order received', 'Customer placed order #000056 (Cash on Delivery) — total ₱16,160.00.', '../admin/orders.php', 0, '2026-10-28 04:11:28', '2026-10-28 04:11:28'),
(63, 30, 'order', 'Order placed successfully', 'Your order #000057 was received. Payment: Cash on Delivery · Total: ₱16,160.00. We\'ll update you as it moves along.', 'order-details.php?id=57', 0, '2026-10-28 04:18:30', '2026-10-28 04:18:30'),
(64, 3, 'order', 'New order received', 'Customer placed order #000057 (Cash on Delivery) — total ₱16,160.00.', '../admin/orders.php', 0, '2026-10-28 04:18:30', '2026-10-28 04:18:30'),
(65, 30, 'order', 'Order placed successfully', 'Your order #000058 was received. Payment: Cash on Delivery · Total: ₱16,160.00. We\'ll update you as it moves along.', 'order-details.php?id=58', 0, '2026-10-28 04:58:35', '2026-10-28 04:58:35'),
(66, 3, 'order', 'New order received', 'Customer placed order #000058 (Cash on Delivery) — total ₱16,160.00.', '../admin/orders.php', 0, '2026-10-28 04:58:35', '2026-10-28 04:58:35'),
(67, 30, 'order', 'Order placed successfully', 'Your order #000059 was received. Payment: Cash on Delivery · Total: ₱16,160.00. We\'ll update you as it moves along.', 'order-details.php?id=59', 0, '2026-10-28 05:34:31', '2026-10-28 05:34:31'),
(68, 3, 'order', 'New order received', 'Customer placed order #000059 (Cash on Delivery) — total ₱16,160.00.', '../admin/orders.php', 0, '2026-10-28 05:34:31', '2026-10-28 05:34:31'),
(69, 30, 'order', 'Order placed successfully', 'Your order #000060 was received. Payment: Cash on Delivery · Total: ₱32,320.00. We\'ll update you as it moves along.', 'order-details.php?id=60', 0, '2026-10-28 05:37:28', '2026-10-28 05:37:28'),
(70, 3, 'order', 'New order received', 'Customer placed order #000060 (Cash on Delivery) — total ₱32,320.00.', '../admin/orders.php', 0, '2026-10-28 05:37:28', '2026-10-28 05:37:28'),
(71, 30, 'order', 'Order placed successfully', 'Your order #000061 was received. Payment: Cash on Delivery · Total: ₱16,160.00. We\'ll update you as it moves along.', 'order-details.php?id=61', 0, '2026-10-28 05:42:32', '2026-10-28 05:42:32'),
(72, 3, 'order', 'New order received', 'Customer placed order #000061 (Cash on Delivery) — total ₱16,160.00.', '../admin/orders.php', 0, '2026-10-28 05:42:32', '2026-10-28 05:42:32'),
(73, 30, 'order', 'Order placed successfully', 'Your order #000062 was received. Payment: Cash on Delivery · Total: ₱12,120.00. We\'ll update you as it moves along.', 'order-details.php?id=62', 0, '2026-10-28 05:50:23', '2026-10-28 05:50:23'),
(74, 3, 'order', 'New order received', 'Customer placed order #000062 (Cash on Delivery) — total ₱12,120.00.', '../admin/orders.php', 0, '2026-10-28 05:50:23', '2026-10-28 05:50:23'),
(75, 30, 'order', 'Order placed successfully', 'Your order #000063 was received. Payment: Cash on Delivery · Total: ₱16,160.00. We\'ll update you as it moves along.', 'order-details.php?id=63', 0, '2026-10-28 05:51:03', '2026-10-28 05:51:03'),
(76, 3, 'order', 'New order received', 'Customer placed order #000063 (Cash on Delivery) — total ₱16,160.00.', '../admin/orders.php', 0, '2026-10-28 05:51:03', '2026-10-28 05:51:03'),
(77, 30, 'order', 'Order placed successfully', 'Your order #000064 was received. Payment: Cash on Delivery · Total: ₱16,160.00. We\'ll update you as it moves along.', 'order-details.php?id=64', 0, '2026-10-28 05:59:51', '2026-10-28 05:59:51'),
(78, 3, 'order', 'New order received', 'Customer placed order #000064 (Cash on Delivery) — total ₱16,160.00.', '../admin/orders.php', 0, '2026-10-28 05:59:51', '2026-10-28 05:59:51'),
(79, 30, 'order', 'Order placed successfully', 'Your order #000065 was received. Payment: Cash on Delivery · Total: ₱12,120.00. We\'ll update you as it moves along.', 'order-details.php?id=65', 0, '2026-10-28 06:06:03', '2026-10-28 06:06:03'),
(80, 3, 'order', 'New order received', 'Customer placed order #000065 (Cash on Delivery) — total ₱12,120.00.', '../admin/orders.php', 0, '2026-10-28 06:06:03', '2026-10-28 06:06:03'),
(81, 30, 'order', 'Order placed successfully', 'Your order #000066 was received. Payment: Cash on Delivery · Total: ₱12,120.00. We\'ll update you as it moves along.', 'order-details.php?id=66', 0, '2026-10-28 06:13:57', '2026-10-28 06:13:57'),
(82, 3, 'order', 'New order received', 'Customer placed order #000066 (Cash on Delivery) — total ₱12,120.00.', '../admin/orders.php', 0, '2026-10-28 06:13:57', '2026-10-28 06:13:57');

-- --------------------------------------------------------

--
-- Table structure for table `notification_deletes`
--

CREATE TABLE `notification_deletes` (
  `notification_id` varchar(64) NOT NULL,
  `deleted_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notification_deletes`
--

INSERT INTO `notification_deletes` (`notification_id`, `deleted_at`) VALUES
('customer-30', '2026-10-28 03:33:20'),
('customer-31', '2026-10-28 03:33:20'),
('customer-32', '2026-10-28 03:33:19'),
('customer-33', '2026-10-28 03:33:19'),
('customer-34', '2026-10-28 03:33:18'),
('customer-36', '2026-10-28 03:33:18'),
('layaway-5', '2026-10-28 03:33:19'),
('order-40', '2026-10-28 03:33:20'),
('order-41', '2026-10-28 03:33:20'),
('order-42', '2026-10-28 03:33:20'),
('order-43', '2026-10-28 03:33:19'),
('order-44', '2026-10-28 03:33:19'),
('order-45', '2026-10-28 03:33:19'),
('order-46', '2026-10-28 03:33:18'),
('order-47', '2026-10-28 03:33:18'),
('order-48', '2026-10-28 03:33:18'),
('order-49', '2026-10-28 03:33:17'),
('order-50', '2026-10-28 03:33:17'),
('order-51', '2026-10-28 03:33:17'),
('order-52', '2026-10-28 03:33:17'),
('order-53', '2026-10-28 03:33:17'),
('order-54', '2026-10-28 03:33:16'),
('order-55', '2026-10-28 06:07:39'),
('order-56', '2026-10-28 06:07:39'),
('order-57', '2026-10-28 06:07:38'),
('order-58', '2026-10-28 06:07:38'),
('order-59', '2026-10-28 06:07:38'),
('order-60', '2026-10-28 06:07:38'),
('order-61', '2026-10-28 06:07:38'),
('order-62', '2026-10-28 06:07:37'),
('order-63', '2026-10-28 06:07:37'),
('order-64', '2026-10-28 06:07:37'),
('order-65', '2026-10-28 06:07:36'),
('stock-20', '2026-10-28 06:07:39'),
('stock-21', '2026-10-28 06:07:39');

-- --------------------------------------------------------

--
-- Table structure for table `notification_reads`
--

CREATE TABLE `notification_reads` (
  `notification_id` varchar(64) NOT NULL,
  `read_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notification_reads`
--

INSERT INTO `notification_reads` (`notification_id`, `read_at`) VALUES
('customer-30', '2026-10-28 03:33:20'),
('customer-31', '2026-10-28 03:33:20'),
('customer-32', '2026-10-28 03:33:19'),
('customer-33', '2026-10-28 03:33:19'),
('customer-34', '2026-10-28 03:33:06'),
('customer-36', '2026-10-28 03:33:06'),
('layaway-5', '2026-10-28 03:33:19'),
('order-40', '2026-10-28 03:33:20'),
('order-41', '2026-10-28 03:33:20'),
('order-42', '2026-10-28 03:33:20'),
('order-43', '2026-10-28 03:33:19'),
('order-44', '2026-10-28 03:33:19'),
('order-45', '2026-10-28 03:33:19'),
('order-46', '2026-10-28 03:33:06'),
('order-47', '2026-10-28 03:33:06'),
('order-48', '2026-10-28 03:33:06'),
('order-49', '2026-10-28 03:33:06'),
('order-50', '2026-10-28 03:33:06'),
('order-51', '2026-10-28 03:33:06'),
('order-52', '2026-10-28 03:33:06'),
('order-53', '2026-10-28 03:33:06'),
('order-54', '2026-10-28 03:33:06'),
('order-55', '2026-10-28 03:33:49'),
('order-56', '2026-10-28 04:34:05'),
('order-57', '2026-10-28 04:34:05'),
('order-58', '2026-10-28 05:00:10'),
('order-59', '2026-10-28 05:34:41'),
('order-60', '2026-10-28 05:37:39'),
('order-61', '2026-10-28 06:07:19'),
('order-62', '2026-10-28 06:07:19'),
('order-63', '2026-10-28 06:07:19'),
('order-64', '2026-10-28 06:07:19'),
('order-65', '2026-10-28 06:07:19'),
('order-66', '2026-10-28 06:14:08'),
('stock-20', '2026-10-28 06:07:19'),
('stock-21', '2026-10-28 06:07:19');

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `status` enum('pending','processing','shipped','delivered','cancelled') DEFAULT 'pending',
  `shipping_address` text DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `payment_reference` varchar(100) DEFAULT NULL,
  `payment_proof` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `discount_percent` decimal(5,2) DEFAULT NULL,
  `discount_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_status` varchar(32) NOT NULL DEFAULT '',
  `payment_verified_at` datetime DEFAULT NULL,
  `payment_verified_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `orders`
--

INSERT INTO `orders` (`id`, `user_id`, `email`, `total_amount`, `status`, `shipping_address`, `phone`, `notes`, `payment_method`, `payment_reference`, `payment_proof`, `created_at`, `discount_percent`, `discount_amount`, `payment_status`, `payment_verified_at`, `payment_verified_by`) VALUES
(39, 30, 'cybelle.canas@gmail.com', 16160.00, 'delivered', 'canlanipa', '09764689232', '', 'gcash', '252525', 'uploads/payment_proofs/gcash_30_1791878290_2e5a0a85.jpg', '2026-10-13 07:58:10', NULL, 0.00, '', NULL, NULL),
(40, 30, 'cybelle.canas@gmail.com', 70125.00, 'delivered', 'canlanipa', '09764689232', '', 'gcash', '2433546576878', 'uploads/payment_proofs/gcash_30_1792811712_984ac954.webp', '2026-10-24 03:15:12', 15.00, 12375.00, '', NULL, NULL),
(41, 31, 'cybelle.canas1@gmail.com', 16500.00, 'delivered', 'canlanipa', '092353525325', '', 'gcash', '1431435547685', 'uploads/payment_proofs/gcash_31_1792828618_47b9ab83.png', '2026-10-24 07:56:58', NULL, 0.00, '', NULL, NULL),
(42, 31, 'cybelle.canas1@gmail.com', 16500.00, 'delivered', 'canlanipa', '092353525325', '', 'cod', NULL, NULL, '2026-10-24 08:29:49', NULL, 0.00, '', NULL, NULL),
(43, 32, 'cybelle.canas2@gmail.com', 31040.00, 'processing', 'afasf', '09510473121', '', 'gcash', '1233214124124', 'uploads/payment_proofs/gcash_32_1792908141_3534af8b.jpg', '2026-10-25 06:02:21', 3.00, 960.00, '', NULL, NULL),
(44, 32, 'cybelle.canas2@gmail.com', 61200.00, 'pending', 'afasf', '09510473121', '', 'cod', NULL, NULL, '2026-10-25 10:56:13', 15.00, 10800.00, '', NULL, NULL),
(45, 33, 'fayesarsalijo@gmail.com', 18000.00, 'pending', 'ffvfbfbfgb', '092353525325', '', 'cod', NULL, NULL, '2026-10-25 12:27:55', NULL, 0.00, '', NULL, NULL),
(46, 34, 'cybelle.canas16@gmail.com', 18000.00, 'pending', 'cassaf', '092353525325', '', 'cod', NULL, NULL, '2026-10-27 03:31:16', NULL, 0.00, '', NULL, NULL),
(47, 30, NULL, 8080.00, 'pending', 'canlanipa', '09764689232', '', 'cod', NULL, NULL, '2026-10-28 01:36:22', NULL, 0.00, '', NULL, NULL),
(48, 30, NULL, 16160.00, 'pending', 'canlanipa', '09764689232', '', 'cod', NULL, NULL, '2026-10-28 02:26:02', NULL, 0.00, '', NULL, NULL),
(49, 30, NULL, 12120.00, 'pending', 'canlanipa', '09764689232', '', 'cod', NULL, NULL, '2026-10-28 02:28:37', NULL, 0.00, '', NULL, NULL),
(50, 30, NULL, 12120.00, 'pending', 'canlanipa', '09764689232', '', 'cod', NULL, NULL, '2026-10-28 02:29:23', NULL, 0.00, '', NULL, NULL),
(51, 30, NULL, 12120.00, 'pending', 'canlanipa', '09764689232', '', 'cod', NULL, NULL, '2026-10-28 02:29:49', NULL, 0.00, '', NULL, NULL),
(52, 30, NULL, 16160.00, 'pending', 'canlanipa', '09764689232', '', 'cod', NULL, NULL, '2026-10-28 02:34:25', NULL, 0.00, '', NULL, NULL),
(53, 30, NULL, 16160.00, 'pending', 'canlanipa', '09764689232', '', 'cod', NULL, NULL, '2026-10-28 02:43:06', NULL, 0.00, '', NULL, NULL),
(54, 30, NULL, 16160.00, 'pending', 'canlanipa', '09764689232', '', 'cod', NULL, NULL, '2026-10-28 03:21:02', NULL, 0.00, '', NULL, NULL),
(55, 30, NULL, 16160.00, 'pending', 'canlanipa', '09764689232', '', 'cod', NULL, NULL, '2026-10-28 03:33:37', NULL, 0.00, '', NULL, NULL),
(56, 30, NULL, 16160.00, 'pending', 'canlanipa', '09764689232', '', 'cod', NULL, NULL, '2026-10-28 04:11:28', NULL, 0.00, '', NULL, NULL),
(57, 30, NULL, 16160.00, 'pending', 'canlanipa', '09764689232', '', 'cod', NULL, NULL, '2026-10-28 04:18:30', NULL, 0.00, '', NULL, NULL),
(58, 30, NULL, 16160.00, 'pending', 'canlanipa', '09764689232', '', 'cod', NULL, NULL, '2026-10-28 04:58:35', NULL, 0.00, '', NULL, NULL),
(59, 30, NULL, 16160.00, 'pending', 'canlanipa', '09764689232', '', 'cod', NULL, NULL, '2026-10-28 05:34:31', NULL, 0.00, '', NULL, NULL),
(60, 30, NULL, 32320.00, 'pending', 'canlanipa', '09764689232', '', 'cod', NULL, NULL, '2026-10-28 05:37:28', NULL, 0.00, '', NULL, NULL),
(61, 30, NULL, 16160.00, 'pending', 'canlanipa', '09764689232', '', 'cod', NULL, NULL, '2026-10-28 05:42:32', NULL, 0.00, '', NULL, NULL),
(62, 30, NULL, 12120.00, 'pending', 'canlanipa', '09764689232', '', 'cod', NULL, NULL, '2026-10-28 05:50:23', NULL, 0.00, '', NULL, NULL),
(63, 30, NULL, 16160.00, 'pending', 'canlanipa', '09764689232', '', 'cod', NULL, NULL, '2026-10-28 05:51:03', NULL, 0.00, '', NULL, NULL),
(64, 30, NULL, 16160.00, 'pending', 'canlanipa', '09764689232', '', 'cod', NULL, NULL, '2026-10-28 05:59:51', NULL, 0.00, '', NULL, NULL),
(65, 30, NULL, 12120.00, 'pending', 'canlanipa', '09764689232', '', 'cod', NULL, NULL, '2026-10-28 06:06:03', NULL, 0.00, '', NULL, NULL),
(66, 30, NULL, 12120.00, 'pending', 'canlanipa', '09764689232', '', 'cod', NULL, NULL, '2026-10-28 06:13:57', NULL, 0.00, '', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `order_items`
--

CREATE TABLE `order_items` (
  `id` int(11) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `price` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `order_items`
--

INSERT INTO `order_items` (`id`, `order_id`, `product_id`, `quantity`, `price`) VALUES
(40, 39, 18, 1, 16000.00),
(41, 40, 19, 5, 16500.00),
(42, 41, 19, 1, 16500.00),
(43, 42, 19, 1, 16500.00),
(44, 43, 20, 2, 16000.00),
(49, 48, 20, 1, 16000.00),
(50, 49, 21, 1, 12000.00),
(51, 50, 21, 1, 12000.00),
(52, 51, 21, 1, 12000.00),
(53, 52, 20, 1, 16000.00),
(54, 53, 20, 1, 16000.00),
(55, 54, 18, 1, 16000.00),
(56, 55, 18, 1, 16000.00),
(57, 56, 18, 1, 16000.00),
(58, 57, 18, 1, 16000.00),
(59, 58, 18, 1, 16000.00),
(60, 59, 18, 1, 16000.00),
(61, 60, 18, 2, 16000.00),
(62, 61, 18, 1, 16000.00),
(63, 62, 21, 1, 12000.00),
(64, 63, 20, 1, 16000.00),
(65, 64, 18, 1, 16000.00),
(66, 65, 21, 1, 12000.00),
(67, 66, 21, 1, 12000.00);

-- --------------------------------------------------------

--
-- Table structure for table `order_payments`
--

CREATE TABLE `order_payments` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `reference_no` varchar(100) DEFAULT NULL,
  `proof_image` varchar(255) DEFAULT NULL,
  `status` enum('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  `paid_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `verified_at` timestamp NULL DEFAULT NULL,
  `verified_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `reference_note` varchar(255) DEFAULT NULL,
  `proof_path` varchar(500) DEFAULT NULL,
  `status` enum('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int(11) NOT NULL,
  `name` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `stock` int(11) DEFAULT 0,
  `released_stock` int(11) NOT NULL DEFAULT 0,
  `sold_stock` int(11) NOT NULL DEFAULT 0,
  `image` varchar(255) DEFAULT NULL,
  `featured` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `karat` varchar(20) DEFAULT NULL,
  `weight` decimal(10,2) DEFAULT NULL,
  `unreleased_stock` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `name`, `description`, `price`, `category_id`, `stock`, `released_stock`, `sold_stock`, `image`, `featured`, `created_at`, `updated_at`, `karat`, `weight`, `unreleased_stock`) VALUES
(18, 'Golden Blossom Pendant Necklace', 'Elegant flower-shaped pendant necklace with pearly petals, sparkling stones, and a teardrop accent.', 16000.00, 3, 100, 9, 5, '1793153034_necklace_jpg', 1, '2026-10-13 07:43:42', '2026-10-28 06:05:45', '18K Yellow Gold', 2.00, 91),
(19, 'Royal Infinity Solitaire Ring', 'Royal Infinity Solitaire Ring', 16500.00, 1, 100, 7, 7, '1793153048_ring_jpg', 1, '2026-10-24 00:37:24', '2026-10-28 06:05:45', '18K Rose Gold', 2.00, 93),
(20, 'Gold Solitaire Ring', 'Elegant yellow gold ring featuring a round, sparkling solitaire stone in a classic four-prong setting. Perfect for engagements, gifts, and everyday wear.', 16000.00, 1, 100, 4, 5, '1793153061_ring1_jpg', 1, '2026-10-25 02:52:57', '2026-10-28 06:05:45', '18K', 2.00, 96),
(21, 'Geometric Gold Ring', 'Simple gold ring with a geometric design.', 12000.00, 1, 100, 0, 3, '1793153077_ring2_jpg', 1, '2026-10-25 04:01:29', '2026-10-28 06:13:57', '18K', 2.00, 98);

-- --------------------------------------------------------

--
-- Table structure for table `reviews`
--

CREATE TABLE `reviews` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `rating` tinyint(4) NOT NULL,
  `title` varchar(150) DEFAULT NULL,
  `comment` text NOT NULL,
  `is_verified` tinyint(1) NOT NULL DEFAULT 0,
  `status` varchar(20) NOT NULL DEFAULT 'approved',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `reviews`
--

INSERT INTO `reviews` (`id`, `product_id`, `user_id`, `order_id`, `rating`, `title`, `comment`, `is_verified`, `status`, `created_at`, `updated_at`) VALUES
(3, 21, 32, NULL, 5, NULL, 'Beautiful design and shiny gold finish. Perfect for everyday wear', 0, 'approved', '2026-10-25 11:13:50', '2026-10-25 11:13:50');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `email_verified` tinyint(1) NOT NULL DEFAULT 0,
  `verification_token` varchar(64) DEFAULT NULL,
  `verification_expires` datetime DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `role` enum('admin','user') DEFAULT 'user',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `reset_token` varchar(64) DEFAULT NULL,
  `reset_expires` datetime DEFAULT NULL,
  `failed_logins` int(11) NOT NULL DEFAULT 0,
  `lockout_until` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `email_verified`, `verification_token`, `verification_expires`, `password`, `full_name`, `phone`, `address`, `role`, `created_at`, `updated_at`, `reset_token`, `reset_expires`, `failed_logins`, `lockout_until`) VALUES
(3, 'admin', 'admin@jewelryshop.com', 1, NULL, NULL, '$2y$10$Rk4EbShjNC9WYsv74LoImOsOM76pJqL0XzX3xdmi6nXw0eOi2crAK', 'Administrator', NULL, NULL, 'admin', '2026-03-31 05:29:32', '2026-10-27 14:21:38', NULL, NULL, 0, NULL),
(30, 'cybelle1', 'cybelle.canas@gmail.com', 0, NULL, NULL, '$2y$10$0mPjbh8Fe1IV6si6jEv62.tDot9PHnZ1UoEhx0nOs43JyV9Zpf6eW', 'Cybelle Canas Dequit', '09764689232', 'canlanipa', 'user', '2026-10-13 07:44:13', '2026-10-13 07:44:13', NULL, NULL, 0, NULL),
(31, 'cybelle112', 'cybelle.canas1@gmail.com', 0, NULL, NULL, '$2y$10$YpuCCu1JhydLjVtwdKESjOmgGUBq41pQPBKJ3.pVOehYQtOIN2vX.', 'Cybelle Canas Dequit', '092353525325', 'canlanipa', 'user', '2026-10-24 07:55:37', '2026-10-24 07:55:37', NULL, NULL, 0, NULL),
(32, 'cy12321', 'cybelle.canas2@gmail.com', 0, NULL, NULL, '$2y$10$yMjBaWjSG.ReGUg4zawaIOtLlANksNWRVCjwDA1dO2itXLc8rhdRG', 'Cybelle Canas Dequit', '09510473121', 'afasf', 'user', '2026-10-25 03:51:17', '2026-10-25 03:51:17', NULL, NULL, 0, NULL),
(33, 'jundel', 'jundeljay@gmail.com', 0, NULL, NULL, '$2y$10$w/Wv7eAyh11w8bCSgc7tju/jyEWdJ4vPReI7M7bdipcRCgs4Sb04q', 'Jundel Jay Miral', '09510473121', 'dsfdsfdg', 'user', '2026-10-25 12:08:01', '2026-10-25 12:08:01', NULL, NULL, 0, NULL),
(34, 'cybelle16', 'cybelle.canas16@gmail.com', 0, NULL, NULL, '$2y$10$SESXAqhs4MVBpCK42Jao1OG.IfslINB9KVsH9JtwL9ykP5UR/zf5G', 'Cybelle Canas Dequit', '092353525325', 'cassaf', 'user', '2026-10-27 02:55:33', '2026-10-27 02:55:33', NULL, NULL, 0, NULL),
(36, 'cybelle122', 'cybelle.canas122@gmail.com', 0, NULL, NULL, '$2y$10$9m2kD5xQDcBMpNUuyfsED.ju2Q9UXvvO79JWARNazEQApst4yrE2q', 'Cybelle Canas Dequit', '092353525325', 'faasf', 'user', '2026-10-28 00:28:33', '2026-10-28 00:28:33', NULL, NULL, 0, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `user_settings`
--

CREATE TABLE `user_settings` (
  `user_id` int(11) NOT NULL,
  `theme` varchar(20) NOT NULL DEFAULT 'cream',
  `accent` varchar(10) NOT NULL DEFAULT '#b8941f',
  `compact` tinyint(1) NOT NULL DEFAULT 0,
  `animations` tinyint(1) NOT NULL DEFAULT 1,
  `toggle_2fa` tinyint(1) NOT NULL DEFAULT 0,
  `toggle_login_alerts` tinyint(1) NOT NULL DEFAULT 1,
  `toggle_order_updates` tinyint(1) NOT NULL DEFAULT 1,
  `toggle_promos` tinyint(1) NOT NULL DEFAULT 1,
  `toggle_stock` tinyint(1) NOT NULL DEFAULT 0,
  `toggle_newsletter` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_vouchers`
--

CREATE TABLE `user_vouchers` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `discount_percent` decimal(5,2) NOT NULL,
  `is_used` tinyint(1) NOT NULL DEFAULT 0,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_vouchers`
--

INSERT INTO `user_vouchers` (`id`, `user_id`, `discount_percent`, `is_used`, `used_at`, `created_at`) VALUES
(3, 30, 10.00, 0, NULL, '2026-10-24 08:04:23');

-- --------------------------------------------------------

--
-- Table structure for table `wishlist`
--

CREATE TABLE `wishlist` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `wishlist`
--

INSERT INTO `wishlist` (`id`, `user_id`, `product_id`, `created_at`) VALUES
(4, 36, 25, '2026-10-28 08:50:28');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin_preferences`
--
ALTER TABLE `admin_preferences`
  ADD PRIMARY KEY (`pref_key`);

--
-- Indexes for table `cart`
--
ALTER TABLE `cart`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_cart_item` (`user_id`,`product_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `feedback`
--
ALTER TABLE `feedback`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `layaway_payments`
--
ALTER TABLE `layaway_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_plan` (`plan_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `layaway_plans`
--
ALTER TABLE `layaway_plans`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_product` (`product_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `layaway_settings`
--
ALTER TABLE `layaway_settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `messages`
--
ALTER TABLE `messages`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_msg` (`user_id`,`subject`,`sender_type`),
  ADD KEY `idx_user_read` (`user_id`,`is_read`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_user_read` (`user_id`,`is_read`),
  ADD KEY `idx_type` (`type`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indexes for table `notification_deletes`
--
ALTER TABLE `notification_deletes`
  ADD PRIMARY KEY (`notification_id`);

--
-- Indexes for table `notification_reads`
--
ALTER TABLE `notification_reads`
  ADD PRIMARY KEY (`notification_id`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_orders_status` (`status`);

--
-- Indexes for table `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `idx_order_items_product` (`product_id`);

--
-- Indexes for table `order_payments`
--
ALTER TABLE `order_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_order` (`order_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD KEY `category_id` (`category_id`);

--
-- Indexes for table `reviews`
--
ALTER TABLE `reviews`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_product` (`user_id`,`product_id`),
  ADD KEY `idx_product_status` (`product_id`,`status`),
  ADD KEY `idx_user` (`user_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_verification_token` (`verification_token`),
  ADD KEY `idx_users_reset` (`reset_token`),
  ADD KEY `idx_users_verify` (`verification_token`);

--
-- Indexes for table `user_settings`
--
ALTER TABLE `user_settings`
  ADD PRIMARY KEY (`user_id`);

--
-- Indexes for table `user_vouchers`
--
ALTER TABLE `user_vouchers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`,`is_used`);

--
-- Indexes for table `wishlist`
--
ALTER TABLE `wishlist`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_user_product` (`user_id`,`product_id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_product` (`product_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `cart`
--
ALTER TABLE `cart`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=79;

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `feedback`
--
ALTER TABLE `feedback`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `layaway_payments`
--
ALTER TABLE `layaway_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `layaway_plans`
--
ALTER TABLE `layaway_plans`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `messages`
--
ALTER TABLE `messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=83;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=67;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=68;

--
-- AUTO_INCREMENT for table `order_payments`
--
ALTER TABLE `order_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `reviews`
--
ALTER TABLE `reviews`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=37;

--
-- AUTO_INCREMENT for table `user_vouchers`
--
ALTER TABLE `user_vouchers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `wishlist`
--
ALTER TABLE `wishlist`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `cart`
--
ALTER TABLE `cart`
  ADD CONSTRAINT `cart_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `cart_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `feedback`
--
ALTER TABLE `feedback`
  ADD CONSTRAINT `feedback_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `order_items`
--
ALTER TABLE `order_items`
  ADD CONSTRAINT `order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `order_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`),
  ADD CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `products_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `user_settings`
--
ALTER TABLE `user_settings`
  ADD CONSTRAINT `fk_user_settings_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
