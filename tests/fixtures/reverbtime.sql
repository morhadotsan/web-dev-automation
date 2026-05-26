-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 20, 2026 at 02:03 PM
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
-- Database: `reverbtime`
--

-- --------------------------------------------------------

--
-- Table structure for table `admin`
--

CREATE TABLE `admin` (
  `admin_id` int(11) NOT NULL,
  `admin_email` varchar(255) NOT NULL,
  `admin_password` varchar(255) NOT NULL,
  `company_fullname` varchar(255) NOT NULL,
  `company_url` varchar(255) NOT NULL,
  `company_title` varchar(255) NOT NULL,
  `company_tagline` varchar(255) NOT NULL,
  `company_address` varchar(255) NOT NULL,
  `company_address_2` varchar(255) NOT NULL,
  `company_email_1` varchar(255) NOT NULL,
  `company_email_2` varchar(255) NOT NULL,
  `company_phone_1` varchar(255) NOT NULL,
  `company_phone_2` varchar(255) NOT NULL,
  `last_login` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `author`
--

CREATE TABLE `author` (
  `author_id` int(11) NOT NULL,
  `author_slug` varchar(255) NOT NULL,
  `author_email` varchar(255) NOT NULL,
  `author_password` varchar(255) NOT NULL,
  `author_name` varchar(255) NOT NULL,
  `author_phone` varchar(255) NOT NULL,
  `login_type` varchar(255) NOT NULL,
  `token` text NOT NULL,
  `author_img` text NOT NULL,
  `trusted_author` varchar(255) NOT NULL,
  `account_status` varchar(255) NOT NULL,
  `register_date` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `blog`
--

CREATE TABLE `blog` (
  `blog_id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `blog_title` varchar(255) NOT NULL,
  `blog_h1` varchar(255) NOT NULL,
  `url_slug` varchar(255) NOT NULL,
  `my_web_url` varchar(255) NOT NULL,
  `blog_author` varchar(255) NOT NULL,
  `blog_category` varchar(255) NOT NULL,
  `blog_image` text NOT NULL,
  `blog_shortDesc` varchar(255) NOT NULL,
  `blog_keywords` varchar(255) NOT NULL,
  `blog_content` text NOT NULL,
  `blog_date` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `blog_check`
--

CREATE TABLE `blog_check` (
  `check_id` int(11) NOT NULL,
  `author_slug` varchar(255) NOT NULL,
  `user_key` varchar(255) NOT NULL,
  `process_type` varchar(255) NOT NULL,
  `check_type` varchar(255) NOT NULL,
  `status` varchar(255) NOT NULL,
  `post_amount` double NOT NULL,
  `user_balance` double NOT NULL,
  `no_of_links` varchar(255) NOT NULL,
  `upload_date` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `blog_comments`
--

CREATE TABLE `blog_comments` (
  `comment_id` int(11) NOT NULL,
  `blog_url` varchar(255) NOT NULL,
  `user_key` varchar(255) NOT NULL,
  `comment_name` varchar(255) NOT NULL,
  `comment_email` varchar(255) NOT NULL,
  `comment` text NOT NULL,
  `comment_date` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `blog_credit`
--

CREATE TABLE `blog_credit` (
  `credit_id` int(11) NOT NULL,
  `author_slug` varchar(255) NOT NULL,
  `author_email` varchar(255) NOT NULL,
  `credit_type` varchar(255) NOT NULL,
  `credit_points` double NOT NULL,
  `number_of_links` varchar(255) NOT NULL,
  `expiry_date` varchar(255) NOT NULL,
  `upload_date` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `blog_pay`
--

CREATE TABLE `blog_pay` (
  `paypal_id` int(11) NOT NULL,
  `user_key` varchar(255) NOT NULL,
  `author_slug` varchar(255) NOT NULL,
  `payer_name` varchar(255) NOT NULL,
  `payer_email` varchar(255) NOT NULL,
  `unit_amt` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `credit_type` varchar(255) NOT NULL,
  `total` int(11) NOT NULL,
  `status` varchar(255) NOT NULL,
  `upload_date` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `blog_pending`
--

CREATE TABLE `blog_pending` (
  `p_blog_id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `p_blog_title` varchar(255) NOT NULL,
  `p_blog_h1` varchar(255) NOT NULL,
  `url_slug` varchar(255) NOT NULL,
  `my_web_url` varchar(255) NOT NULL,
  `post_type` varchar(255) NOT NULL,
  `post_amount` varchar(255) NOT NULL,
  `post_status` varchar(255) NOT NULL,
  `p_blog_author` varchar(255) NOT NULL,
  `p_blog_category` varchar(255) NOT NULL,
  `p_blog_image` text NOT NULL,
  `p_blog_shortDesc` varchar(255) NOT NULL,
  `p_blog_keywords` varchar(255) NOT NULL,
  `p_blog_content` text NOT NULL,
  `p_blog_date` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `blog_views`
--

CREATE TABLE `blog_views` (
  `blgView_id` int(11) NOT NULL,
  `user_ip` varchar(255) NOT NULL,
  `user_sessKey` varchar(255) NOT NULL,
  `current_page` varchar(255) NOT NULL,
  `my_web_url` varchar(255) NOT NULL,
  `user_device` text NOT NULL,
  `upload_date` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `contact`
--

CREATE TABLE `contact` (
  `contact_id` int(11) NOT NULL,
  `user_ip` varchar(255) NOT NULL,
  `page_url` text NOT NULL,
  `contact_name` varchar(255) NOT NULL,
  `contact_email` varchar(255) NOT NULL,
  `contact_phone` varchar(255) NOT NULL,
  `contact_website` text NOT NULL,
  `company_name` varchar(255) NOT NULL,
  `contact_service` varchar(255) NOT NULL,
  `contact_message` text NOT NULL,
  `msg_status` varchar(255) NOT NULL,
  `contact_date` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `csr_images`
--

CREATE TABLE `csr_images` (
  `csr_imageid` int(11) NOT NULL,
  `actual_image` text NOT NULL,
  `image_slug` text NOT NULL,
  `upload_date` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `link_insertion`
--

CREATE TABLE `link_insertion` (
  `link_ins_id` int(11) NOT NULL,
  `admin_slug` varchar(255) NOT NULL,
  `target_website` varchar(255) NOT NULL,
  `link_category` varchar(255) NOT NULL,
  `link_quantity` varchar(255) NOT NULL,
  `target_url` text NOT NULL,
  `anchor_text` varchar(255) NOT NULL,
  `referring_url` text NOT NULL,
  `additional_paragraph` text NOT NULL,
  `upload_date` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `meta`
--

CREATE TABLE `meta` (
  `meta_id` int(11) NOT NULL,
  `canonical_url` mediumtext NOT NULL,
  `page_title` mediumtext NOT NULL,
  `meta_keywords` mediumtext NOT NULL,
  `meta_desc` mediumtext NOT NULL,
  `robot_index` mediumtext NOT NULL,
  `robot_follow` mediumtext NOT NULL,
  `fb_ogtitle` mediumtext NOT NULL,
  `pg_imgLink` mediumtext NOT NULL,
  `upload_date` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `music`
--

CREATE TABLE `music` (
  `music_id` int(11) NOT NULL,
  `published_by` varchar(100) NOT NULL,
  `artist_name` varchar(100) NOT NULL,
  `music_title` varchar(100) NOT NULL,
  `artwork` mediumtext NOT NULL,
  `artist_detail` mediumtext NOT NULL,
  `download_link` varchar(255) NOT NULL,
  `upload_date` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sorted_blogs`
--

CREATE TABLE `sorted_blogs` (
  `sort_id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `blog_id` int(11) NOT NULL,
  `upload_date` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `unique_vts`
--

CREATE TABLE `unique_vts` (
  `uvts_id` int(11) NOT NULL,
  `user_ip` varchar(255) NOT NULL,
  `user_key` text NOT NULL,
  `page_url` text NOT NULL,
  `user_deviceData` text NOT NULL,
  `upload_date` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin`
--
ALTER TABLE `admin`
  ADD PRIMARY KEY (`admin_id`);

--
-- Indexes for table `author`
--
ALTER TABLE `author`
  ADD PRIMARY KEY (`author_id`);

--
-- Indexes for table `blog`
--
ALTER TABLE `blog`
  ADD PRIMARY KEY (`blog_id`);

--
-- Indexes for table `blog_check`
--
ALTER TABLE `blog_check`
  ADD PRIMARY KEY (`check_id`);

--
-- Indexes for table `blog_comments`
--
ALTER TABLE `blog_comments`
  ADD PRIMARY KEY (`comment_id`);

--
-- Indexes for table `blog_credit`
--
ALTER TABLE `blog_credit`
  ADD PRIMARY KEY (`credit_id`);

--
-- Indexes for table `blog_pay`
--
ALTER TABLE `blog_pay`
  ADD PRIMARY KEY (`paypal_id`);

--
-- Indexes for table `blog_pending`
--
ALTER TABLE `blog_pending`
  ADD PRIMARY KEY (`p_blog_id`);

--
-- Indexes for table `blog_views`
--
ALTER TABLE `blog_views`
  ADD PRIMARY KEY (`blgView_id`);

--
-- Indexes for table `contact`
--
ALTER TABLE `contact`
  ADD PRIMARY KEY (`contact_id`);

--
-- Indexes for table `csr_images`
--
ALTER TABLE `csr_images`
  ADD PRIMARY KEY (`csr_imageid`);

--
-- Indexes for table `link_insertion`
--
ALTER TABLE `link_insertion`
  ADD PRIMARY KEY (`link_ins_id`);

--
-- Indexes for table `meta`
--
ALTER TABLE `meta`
  ADD PRIMARY KEY (`meta_id`);

--
-- Indexes for table `music`
--
ALTER TABLE `music`
  ADD PRIMARY KEY (`music_id`);

--
-- Indexes for table `sorted_blogs`
--
ALTER TABLE `sorted_blogs`
  ADD PRIMARY KEY (`sort_id`),
  ADD KEY `admin_id` (`admin_id`),
  ADD KEY `blog_id` (`blog_id`);

--
-- Indexes for table `unique_vts`
--
ALTER TABLE `unique_vts`
  ADD PRIMARY KEY (`uvts_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admin`
--
ALTER TABLE `admin`
  MODIFY `admin_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `author`
--
ALTER TABLE `author`
  MODIFY `author_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3081;

--
-- AUTO_INCREMENT for table `blog`
--
ALTER TABLE `blog`
  MODIFY `blog_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13353;

--
-- AUTO_INCREMENT for table `blog_check`
--
ALTER TABLE `blog_check`
  MODIFY `check_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10560;

--
-- AUTO_INCREMENT for table `blog_comments`
--
ALTER TABLE `blog_comments`
  MODIFY `comment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1255;

--
-- AUTO_INCREMENT for table `blog_credit`
--
ALTER TABLE `blog_credit`
  MODIFY `credit_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1653;

--
-- AUTO_INCREMENT for table `blog_pay`
--
ALTER TABLE `blog_pay`
  MODIFY `paypal_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=314;

--
-- AUTO_INCREMENT for table `blog_pending`
--
ALTER TABLE `blog_pending`
  MODIFY `p_blog_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `blog_views`
--
ALTER TABLE `blog_views`
  MODIFY `blgView_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1071713;

--
-- AUTO_INCREMENT for table `contact`
--
ALTER TABLE `contact`
  MODIFY `contact_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=137;

--
-- AUTO_INCREMENT for table `csr_images`
--
ALTER TABLE `csr_images`
  MODIFY `csr_imageid` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9862;

--
-- AUTO_INCREMENT for table `link_insertion`
--
ALTER TABLE `link_insertion`
  MODIFY `link_ins_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `meta`
--
ALTER TABLE `meta`
  MODIFY `meta_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=67;

--
-- AUTO_INCREMENT for table `music`
--
ALTER TABLE `music`
  MODIFY `music_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `sorted_blogs`
--
ALTER TABLE `sorted_blogs`
  MODIFY `sort_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `unique_vts`
--
ALTER TABLE `unique_vts`
  MODIFY `uvts_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
