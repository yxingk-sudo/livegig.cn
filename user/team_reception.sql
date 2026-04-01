-- phpMyAdmin SQL Dump
-- version 4.4.15.10
-- https://www.phpmyadmin.net
--
-- Host: localhost
-- Generation Time: 2025-08-29 01:27:19
-- 服务器版本： 5.7.44-log
-- PHP Version: 5.6.40

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `team_reception`
--

-- --------------------------------------------------------

--
-- 表的结构 `companies`
--

CREATE TABLE IF NOT EXISTS `companies` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `address` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

--
-- 转存表中的数据 `companies`
--

INSERT INTO `companies` (`id`, `name`, `contact_person`, `phone`, `email`, `address`, `created_at`) VALUES
(1, '英皇娱乐', '张三', '13800138001', 'zhangsan@alibaba.com', '杭州市西湖区', '2025-08-09 13:25:01'),
(2, '环球唱片', '李四', '13800138002', 'lisi@tencent.com', '深圳市南山区', '2025-08-09 13:25:01'),
(3, '艺能制作', '王五', '13800138003', 'wangwu@baidu.com', '北京市海淀区', '2025-08-09 13:25:01');

-- --------------------------------------------------------

--
-- 表的结构 `departments`
--

CREATE TABLE IF NOT EXISTS `departments` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=MyISAM AUTO_INCREMENT=68 DEFAULT CHARSET=utf8;

--
-- 转存表中的数据 `departments`
--

INSERT INTO `departments` (`id`, `project_id`, `name`, `description`, `created_at`) VALUES
(1, 1, 'Director Team导演组', '导演团队', '2025-08-09 13:25:01'),
(2, 1, 'Band/乐队', '乐手团队', '2025-08-09 13:25:01'),
(3, 2, '舞蹈', '舞蹈员团队', '2025-08-09 13:25:01'),
(4, 2, '和音', '和音团队', '2025-08-09 13:25:01'),
(9, 1, '管理组', '负责项目管理和协调工作', '2025-08-10 03:29:26'),
(10, 1, '行动组', '负责现场执行和后勤保障', '2025-08-10 03:29:26'),
(11, 1, 'Backing singer/和音', '负责和声演唱和音乐配合', '2025-08-10 03:29:26'),
(12, 1, 'Dancers/舞蹈', '负责舞蹈编排和表演', '2025-08-10 03:29:26'),
(14, 1, 'Dresser/服装组', '负责服装管理和造型搭配', '2025-08-10 03:29:26'),
(15, 4, 'Band/乐队', '负责音乐演奏和现场伴奏', '2025-08-10 03:29:26'),
(16, 4, 'Director Team/导演组', '负责整体节目策划和导演工作', '2025-08-10 03:29:26'),
(17, 4, 'Managements/管理组', '负责项目管理和协调工作', '2025-08-10 03:29:26'),
(18, 4, 'Operation/行动组', '负责现场执行和后勤保障', '2025-08-10 03:29:26'),
(19, 4, 'Backing singer/和音', '负责和声演唱和音乐配合', '2025-08-10 03:29:26'),
(20, 4, 'Dancers/舞蹈', '负责舞蹈编排和表演', '2025-08-10 03:29:26'),
(21, 4, 'Makeup/妆发组', '负责演员化妆和造型', '2025-08-10 03:29:26'),
(22, 4, 'Dresser/服装组', '负责服装管理和造型搭配', '2025-08-10 03:29:26'),
(23, 7, 'Band/乐队', '负责音乐演奏和现场伴奏', '2025-08-10 03:29:26'),
(24, 7, 'Director Team导演组', '负责整体节目策划和导演工作', '2025-08-10 03:29:26'),
(25, 7, 'Managements/管理组', '负责项目管理和协调工作', '2025-08-10 03:29:26'),
(26, 7, 'Operation/行动组', '负责现场执行和后勤保障', '2025-08-10 03:29:26'),
(27, 7, 'Backing singer/和音', '负责和声演唱和音乐配合', '2025-08-10 03:29:26'),
(28, 7, 'Dancers/舞蹈', '负责舞蹈编排和表演', '2025-08-10 03:29:26'),
(29, 7, 'Makeup/妆发组', '负责演员化妆和造型', '2025-08-10 03:29:26'),
(30, 7, 'Dresser/服装组', '负责服装管理和造型搭配', '2025-08-10 03:29:26'),
(31, 2, '乐队', '负责音乐演奏和现场伴奏', '2025-08-10 03:29:26'),
(32, 2, '导演组', '负责整体节目策划和导演工作', '2025-08-10 03:29:26'),
(33, 2, '管理组', '负责项目管理和协调工作', '2025-08-10 03:29:26'),
(34, 2, '行动组', '负责现场执行和后勤保障', '2025-08-10 03:29:26'),
(35, 2, '化妆组', '负责演员化妆和造型', '2025-08-10 03:29:26'),
(36, 2, '服装组', '负责服装管理和造型搭配', '2025-08-10 03:29:26'),
(37, 3, 'Band/乐队', '负责音乐演奏和现场伴奏', '2025-08-10 03:29:26'),
(38, 3, 'Director Team/导演组', '负责整体节目策划和导演工作', '2025-08-10 03:29:26'),
(39, 3, 'Managements/管理组', '负责项目管理和协调工作', '2025-08-10 03:29:26'),
(40, 3, 'Operation/行动组', '负责现场执行和后勤保障', '2025-08-10 03:29:26'),
(41, 3, 'Backing singer/和音', '负责和声演唱和音乐配合', '2025-08-10 03:29:26'),
(42, 3, 'Dancers/舞蹈', '负责舞蹈编排和表演', '2025-08-10 03:29:26'),
(43, 3, 'Make up/妆发组', '负责演员化妆和造型', '2025-08-10 03:29:26'),
(44, 3, 'Dresser/服装组', '负责服装管理和造型搭配', '2025-08-10 03:29:26'),
(63, 4, 'Artist Guest/艺人嘉宾', '', '2025-08-19 06:57:23'),
(62, 4, 'Artist/艺人', '', '2025-08-19 06:57:13'),
(61, 7, 'Sponsor/主办单位', '', '2025-08-13 03:45:33'),
(59, 7, 'Artist/艺人', '', '2025-08-13 02:42:05'),
(60, 7, 'Artist Guest/艺人嘉宾', '', '2025-08-13 02:44:29'),
(64, 4, 'Sponsor/主办单位', '', '2025-08-19 07:01:11'),
(65, 3, 'Artist/艺人', 'Artist/艺人', '2025-08-28 08:43:04'),
(67, 3, 'Sponsor/主办单位', '', '2025-08-28 08:44:17');

-- --------------------------------------------------------

--
-- 表的结构 `fleet`
--

CREATE TABLE IF NOT EXISTS `fleet` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `fleet_number` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `vehicle_type` enum('car','van','minibus','bus','truck','other') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'car',
  `vehicle_model` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `license_plate` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `driver_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `driver_phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('active','inactive','maintenance') COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `seats` int(11) NOT NULL DEFAULT '5'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- 转存表中的数据 `fleet`
--

INSERT INTO `fleet` (`id`, `project_id`, `fleet_number`, `vehicle_type`, `vehicle_model`, `license_plate`, `driver_name`, `driver_phone`, `status`, `created_at`, `updated_at`, `seats`) VALUES
(1, 1, '1号车', 'van', '别克GB8', '广A12345', '张师傅', '13800138001', 'active', '2025-08-10 17:57:30', '2025-08-18 04:44:39', 5),
(2, 1, '2号车', 'minibus', '丰田考斯特', '广B23456', '李师傅', '13800138002', 'active', '2025-08-10 17:57:30', '2025-08-18 04:44:42', 12),
(3, 1, '3号车', 'bus', '金龙大巴', '广C34567', '王师傅', '13800138003', 'active', '2025-08-10 17:57:30', '2025-08-18 04:44:46', 5),
(4, 1, '4号车', 'car', '奥迪A6', '广D45678', '赵师傅', '13800138004', 'active', '2025-08-10 17:57:30', '2025-08-18 04:44:49', 5),
(5, 1, '5号车', 'truck', '东风货车', '广E56789', '刘师傅', '13800138005', 'active', '2025-08-10 17:57:30', '2025-08-18 04:44:53', 5),
(6, 7, '1号车', 'van', '别克GL8(艺人专用)', '佛B12345', '陈师傅', '13800000000', 'active', '2025-08-10 18:23:20', '2025-08-26 03:05:13', 6),
(7, 7, '2号车', 'van', '别克GL8(艺人专用)', '佛A12345', '王师傅', '', 'active', '2025-08-14 08:28:08', '2025-08-26 03:05:23', 6),
(8, 7, '3号车', 'van', '别克GL8(艺人专用)', '佛B12346', '赵师傅', '', 'active', '2025-08-14 08:28:47', '2025-08-26 03:05:30', 6),
(9, 7, '4号车', 'van', '别克GL8(嘉宾专用)', '佛A12341', '王师傅', '', 'active', '2025-08-14 08:29:10', '2025-08-26 03:05:45', 6),
(10, 7, '5号车', 'van', '别克GB8', '佛A12342', '王师傅', '', 'active', '2025-08-14 08:29:28', '2025-08-18 04:56:39', 5),
(11, 7, '6号车', 'van', '别克GL8', '佛A12347', '赵师傅', '13800138001', 'active', '2025-08-14 08:33:02', '2025-08-18 04:56:42', 5),
(12, 7, '7号车', 'van', '别克GL8', '佛B12348', '赵师傅', '13800138003', 'active', '2025-08-14 08:35:26', '2025-08-18 04:56:44', 5),
(13, 7, '8号车', 'minibus', '丰田考斯特', '佛A12349', '赵师傅', '', 'active', '2025-08-14 08:36:54', '2025-08-18 04:44:18', 18),
(14, 7, '9号车', 'minibus', '丰田考斯特', '佛A12340', '赵师傅', '', 'active', '2025-08-14 08:37:11', '2025-08-18 07:05:33', 18),
(15, 4, '1号车', 'van', '别克GL8', '粤BX27J9', '张祥军', '13926724344', 'active', '2025-08-19 06:52:05', '2025-08-26 09:42:54', 6),
(16, 4, '2号车', 'van', '别克GL8', '粤B69TD0', '梁炳枪', '13549458873', 'active', '2025-08-19 06:52:34', '2025-08-26 09:43:01', 6),
(17, 4, '3号车', 'van', '别克GL8', '粤BPH499', '钟华茂', '13632677889', 'active', '2025-08-19 06:53:02', '2025-08-26 09:43:09', 6),
(18, 4, '4号车', 'van', '别克GL8(艺人专用)', '粤B9Y778', '李师傅', '13926724344', 'active', '2025-08-19 06:54:35', '2025-08-26 09:42:20', 6),
(19, 4, '5号车', 'van', '别克GL8(艺人专用)', '粤BA27N8', '吴才波', '18207591454', 'active', '2025-08-19 06:55:03', '2025-08-26 09:42:31', 6),
(20, 4, '6号车', 'van', '别克GL8(艺人专用)', '粤B89TB8', '张凯宇', '13686032131', 'active', '2025-08-19 06:55:37', '2025-08-26 09:42:46', 6),
(21, 4, '7号车', 'minibus', '考斯特', '粤BKM441', '蒲师傅', '13530204157', 'active', '2025-08-19 06:56:22', '2025-08-19 06:56:22', 22),
(22, 4, '8号车', 'minibus', '考斯特', '粤BKM442', '梁炳枪', '13632677889', 'active', '2025-08-21 09:59:59', '2025-08-21 10:00:05', 22);

-- --------------------------------------------------------

--
-- 表的结构 `hotels`
--

CREATE TABLE IF NOT EXISTS `hotels` (
  `id` int(11) NOT NULL,
  `hotel_name_cn` varchar(255) NOT NULL,
  `hotel_name_en` varchar(255) DEFAULT NULL,
  `province` varchar(100) NOT NULL,
  `city` varchar(100) NOT NULL,
  `district` varchar(100) DEFAULT NULL,
  `address` varchar(500) NOT NULL,
  `room_types` json NOT NULL,
  `total_rooms` int(11) NOT NULL,
  `notes` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

--
-- 转存表中的数据 `hotels`
--

INSERT INTO `hotels` (`id`, `hotel_name_cn`, `hotel_name_en`, `province`, `city`, `district`, `address`, `room_types`, `total_rooms`, `notes`, `created_at`, `updated_at`) VALUES
(1, '佛山东平保利洲际酒店', 'InterContinental Foshan Dongping', '广东', '佛山', '', '广东省佛山市南海区文华南路8号', '["大床房", "双床房", "套房"]', 145, '', '2025-08-10 14:06:50', '2025-08-10 14:36:03'),
(2, '广州新世界酒店', 'New World Guangzhou hotel', '广东', '广州', '白云区', '百顺北路30号', '["大床房", "双床房", "套房"]', 283, '', '2025-08-10 14:42:43', '2025-08-10 14:42:43'),
(3, '广州万富希尔顿酒店', 'Hilton Guangzhou Baiyun', '广东', '广州', '白云区', '广东省广州市白云区云城东路517号', '["大床房", "双床房", "套房", "副总统套房"]', 308, '接待艺人较多有较为成熟接待经验，酒店离场馆最近，房型稍小，因年限相对相比其余两酒店所\r\n以稍旧；\r\n旁边是白云万达广场，商业商场设施非常成熟\r\n另：\r\n1、酒店独有（唯有）一间8楼套房108平方（约1162平方英尺）可供艺人住宿，没有同级套房供\r\n嘉宾入住。\r\n2、108平方套房距离电梯较远、步行需要1分钟到1分半钟', '2025-08-10 14:44:50', '2025-08-10 14:44:50'),
(4, '深圳深铁皇冠假日酒店', 'Crowne Plaza Shenzhen Nanshan', '广东', '深圳', '南山', '广东省深圳市南山区深南大道9819号 ', '["大床房", "双床房", "套房", "副总统套房"]', 289, '', '2025-08-10 14:49:06', '2025-08-10 14:49:22'),
(5, '深圳湾安达仕酒店', 'Andaz Shenzhen Bay', '广东', '深圳', '', '科苑南路', '["大床房", "双床房", "套房"]', 233, '', '2025-08-19 08:23:50', '2025-08-19 08:23:50');

-- --------------------------------------------------------

--
-- 表的结构 `hotel_reports`
--

CREATE TABLE IF NOT EXISTS `hotel_reports` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `personnel_id` int(11) NOT NULL,
  `check_in_date` date NOT NULL,
  `check_out_date` date NOT NULL,
  `hotel_name` varchar(255) DEFAULT NULL,
  `room_type` varchar(100) DEFAULT NULL,
  `room_count` int(11) DEFAULT '1',
  `special_requirements` text,
  `status` enum('pending','confirmed','cancelled') DEFAULT 'pending',
  `reported_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `shared_room_info` varchar(255) DEFAULT NULL COMMENT '共享房间标识'
) ENGINE=MyISAM AUTO_INCREMENT=42 DEFAULT CHARSET=utf8;

--
-- 转存表中的数据 `hotel_reports`
--

INSERT INTO `hotel_reports` (`id`, `project_id`, `personnel_id`, `check_in_date`, `check_out_date`, `hotel_name`, `room_type`, `room_count`, `special_requirements`, `status`, `reported_by`, `created_at`, `updated_at`, `shared_room_info`) VALUES
(1, 7, 16, '2025-08-11', '2025-08-17', '佛山东平保利洲际酒店 - InterContinental Foshan Dongping', '大床房', 1, '', 'confirmed', 7, '2025-08-11 16:55:43', '2025-08-11 17:01:17', NULL),
(2, 7, 84, '2025-08-19', '2025-09-20', '佛山东平保利洲际酒店 - InterContinental Foshan Dongping', '大床房', 1, '', 'confirmed', 14, '2025-08-19 02:13:32', '2025-08-19 06:08:15', NULL),
(35, 1, 1, '2024-12-20', '2024-12-25', '测试酒店 - Test Hotel', '双人房', 1, '需要共享房间', 'pending', 1, '2025-08-28 06:46:40', '2025-08-28 06:46:40', '与张三共享双人房'),
(36, 1, 2, '2024-12-20', '2024-12-25', '测试酒店 - Test Hotel', '单人房', 1, '不需要共享', 'confirmed', 1, '2025-08-28 06:46:40', '2025-08-28 06:46:40', NULL),
(37, 1, 3, '2024-12-22', '2024-12-26', '测试酒店2 - Test Hotel 2', '双人房', 1, '与同事共享', 'pending', 1, '2025-08-28 06:46:40', '2025-08-28 06:46:40', '与李四、王五共享三人房');

-- --------------------------------------------------------

--
-- 表的结构 `meal_reports`
--

CREATE TABLE IF NOT EXISTS `meal_reports` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `personnel_id` int(11) NOT NULL,
  `meal_date` date NOT NULL,
  `meal_type` enum('早餐','午餐','晚餐') NOT NULL,
  `meal_count` int(11) DEFAULT '1',
  `special_requirements` text,
  `status` enum('pending','confirmed','cancelled') DEFAULT 'pending',
  `reported_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `personnel`
--

CREATE TABLE IF NOT EXISTS `personnel` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `username` varchar(50) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `id_card` varchar(50) DEFAULT NULL,
  `gender` enum('男','女','其他') DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `role` varchar(50) DEFAULT 'user',
  `department_id` int(11) DEFAULT '1',
  `position` varchar(100) DEFAULT '员工'
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

--
-- 转存表中的数据 `personnel`
--

INSERT INTO `personnel` (`id`, `name`, `username`, `password`, `is_active`, `phone`, `email`, `id_card`, `gender`, `created_at`, `role`, `department_id`, `position`) VALUES
(21, '袁星', NULL, NULL, 1, '13148432508', '', '440803198610181517', '男', '2025-08-11 14:04:05', 'user', 1, '员工'),
(22, '郑永祥', NULL, NULL, 1, '13148432508', '', '440681199105243636', '男', '2025-08-12 03:46:18', 'user', 1, '员工'),
(23, '梁树洪', NULL, NULL, 1, '13148432512', '', '440126197310061515', '男', '2025-08-12 03:46:18', 'user', 1, '员工'),
(24, '何欣昕', NULL, NULL, 1, '13148432514', '', '340803200002052240', '女', '2025-08-12 03:46:18', 'user', 1, '员工'),
(25, '谭潇', NULL, NULL, 1, '13148432515', '', '441202198802086014', '男', '2025-08-12 03:46:18', 'user', 1, '员工'),
(26, '张卓', NULL, NULL, 1, '19966880154', '', '210902198505022529', '女', '2025-08-12 09:40:35', 'user', 1, '员工'),
(51, '江海迦', NULL, NULL, 1, '19966880102', '', 'H03695378', '女', '2025-08-12 13:32:05', 'user', 1, '员工'),
(52, '曾欣婷', NULL, NULL, 1, '19966880103', '', 'H04464103', '女', '2025-08-12 13:32:05', 'user', 1, '员工'),
(53, '李沛智', NULL, NULL, 1, '19966880104', '', 'H08481874', '男', '2025-08-12 13:32:05', 'user', 1, '员工'),
(54, '陈安怡', NULL, NULL, 1, '19966880105', '', 'H04542847', '女', '2025-08-12 13:32:05', 'user', 1, '员工'),
(55, '刘智辉', NULL, NULL, 1, '19966880106', '', 'H01343152', '男', '2025-08-12 13:32:05', 'user', 1, '员工'),
(56, '刘其展', NULL, NULL, 1, '19966880107', '', 'H00388398', '男', '2025-08-12 13:32:05', 'user', 1, '员工'),
(57, '黄雨', NULL, NULL, 1, '19966880108', '', 'H60960940', '女', '2025-08-12 13:32:05', 'user', 1, '员工'),
(58, '黄文杰', NULL, NULL, 1, '19966880109', '', 'H03400831', '男', '2025-08-12 13:32:05', 'user', 1, '员工'),
(59, '杨佩贤', NULL, NULL, 1, '19966880110', '', 'H03773066', '女', '2025-08-12 13:32:05', 'user', 1, '员工'),
(60, '冯耀民', NULL, NULL, 1, '19966880111', '', 'H10379790', '男', '2025-08-12 13:32:05', 'user', 1, '员工'),
(61, '林德信', NULL, NULL, 1, '19966880113', '', 'H10230830', '男', '2025-08-12 13:32:05', 'user', 1, '员工'),
(62, '潘嘉怡', NULL, NULL, 1, '19966880114', '', 'H01403880', '女', '2025-08-12 13:32:05', 'user', 1, '员工'),
(63, '梁致恒', NULL, NULL, 1, '19966880115', '', 'H00208678', '男', '2025-08-12 13:32:05', 'user', 1, '员工'),
(64, '何健达', NULL, NULL, 1, '19966880116', '', 'H03033057', '男', '2025-08-12 13:32:05', 'user', 1, '员工'),
(65, '陈广全', NULL, NULL, 1, '19966880121', '', 'H00150090', '男', '2025-08-12 13:32:05', 'user', 1, '员工'),
(66, '仇港廷', NULL, NULL, 1, '19966880122', '', 'H04073072', '男', '2025-08-12 13:32:05', 'user', 1, '员工'),
(67, '陈思铭', NULL, NULL, 1, '19966880123', '', 'H04187039', '男', '2025-08-12 13:32:05', 'user', 1, '员工'),
(68, '钟骏贤', NULL, NULL, 1, '19966880124', '', 'H01001886', '男', '2025-08-12 13:32:05', 'user', 1, '员工'),
(69, '黄嘉俊', NULL, NULL, 1, '19966880125', '', 'H03807091', '男', '2025-08-12 13:32:05', 'user', 1, '员工'),
(70, '陈天祐', NULL, NULL, 1, '19966880126', '', 'H03629732', '男', '2025-08-12 13:32:05', 'user', 1, '员工'),
(71, '崔逸珩', NULL, NULL, 1, '19966880127', '', 'H04044811', '男', '2025-08-12 13:32:05', 'user', 1, '员工'),
(72, '查治锋', NULL, NULL, 1, '19966880128', '', 'H03456040', '男', '2025-08-12 13:32:05', 'user', 1, '员工'),
(73, '成俊言', NULL, NULL, 1, '19966880129', '', 'H01090775', '男', '2025-08-12 13:32:05', 'user', 1, '员工'),
(74, '何锦业', NULL, NULL, 1, '19966880130', '', 'H08326207', '男', '2025-08-12 13:32:05', 'user', 1, '员工'),
(75, '刘子聪', NULL, NULL, 1, '19966880131', '', 'H03909222', '男', '2025-08-12 13:32:05', 'user', 1, '员工'),
(76, '萧韵瑶', NULL, NULL, 1, '19966880132', '', 'H03033838', '女', '2025-08-12 13:32:05', 'user', 1, '员工'),
(77, '余明欣', NULL, NULL, 1, '19966880133', '', 'H03336353', '女', '2025-08-12 13:32:05', 'user', 1, '员工'),
(78, '林淑慧', NULL, NULL, 1, '19966880134', '', 'H03561656', '女', '2025-08-12 13:32:05', 'user', 1, '员工'),
(79, '刘熙信', NULL, NULL, 1, '19966880135', '', 'P992500FS', '男', '2025-08-12 13:32:05', 'user', 1, '员工'),
(80, '李颖思', NULL, NULL, 1, '19966880136', '', 'H00708617', '女', '2025-08-12 13:32:05', 'user', 1, '员工'),
(81, '范梓谦', NULL, NULL, 1, '19966880137', '', 'H03259788', '男', '2025-08-12 13:32:05', 'user', 1, '员工'),
(82, '黄仲贤', NULL, NULL, 1, '19966880138', '', 'H09721510', '男', '2025-08-12 13:32:05', 'user', 1, '员工'),
(83, '黄德聪', NULL, NULL, 1, '19966880139', '', 'H01403867', '男', '2025-08-12 13:32:05', 'user', 1, '员工'),
(84, 'NANTON III PADGET EUSTACE', NULL, NULL, 1, '19966880140', '', '642512044', '男', '2025-08-12 13:32:05', 'user', 1, '员工'),
(85, '谭倩雅', NULL, NULL, 1, '19966880141', '', 'H00260536', '女', '2025-08-12 13:32:05', 'user', 1, '员工'),
(86, '李昊嘉', NULL, NULL, 1, '19966880142', '', 'H03232369', '男', '2025-08-12 13:32:05', 'user', 1, '员工'),
(87, '陈俊夫', NULL, NULL, 1, '19966880143', '', 'H03038115', '男', '2025-08-12 13:32:05', 'user', 1, '员工'),
(88, '李家乐', NULL, NULL, 1, '19966880144', '', 'H10477810', '男', '2025-08-12 13:32:05', 'user', 1, '员工'),
(89, '许瞬然', NULL, NULL, 1, '19966880145', '', 'H03430670', '女', '2025-08-12 13:32:05', 'user', 1, '员工'),
(90, '林文思', NULL, NULL, 1, '19966880146', '', 'H03419419', '女', '2025-08-12 13:32:05', 'user', 1, '员工'),
(91, '周凯莉', NULL, NULL, 1, '19966880147', '', 'H03085876', '女', '2025-08-12 13:32:05', 'user', 1, '员工'),
(92, '张惠盈', NULL, NULL, 1, '19966880148', '', 'H03555531', '女', '2025-08-12 13:32:05', 'user', 1, '员工'),
(93, '莫家琪', NULL, NULL, 1, '19966880149', '', 'H03074690', '女', '2025-08-12 13:32:05', 'user', 1, '员工'),
(94, '伍善诗', NULL, NULL, 1, '19966880150', '', 'H03931538', '女', '2025-08-12 13:32:05', 'user', 1, '员工'),
(95, '张倩怡', NULL, NULL, 1, '19966880151', '', 'H03697470', '女', '2025-08-12 13:32:05', 'user', 1, '员工'),
(96, '阮敬雯', NULL, NULL, 1, '19966880152', '', 'H03134851', '女', '2025-08-12 13:32:05', 'user', 1, '员工'),
(97, '刘蔚淇', NULL, NULL, 1, '19966880153', '', 'H07272199', '女', '2025-08-12 13:32:05', 'user', 1, '员工');

-- --------------------------------------------------------

--
-- 表的结构 `personnel_departments`
--

CREATE TABLE IF NOT EXISTS `personnel_departments` (
  `id` int(11) NOT NULL,
  `personnel_id` int(11) NOT NULL,
  `department_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- 表的结构 `personnel_department_view`
--

CREATE TABLE IF NOT EXISTS `personnel_department_view` (
  `id` int(11) DEFAULT NULL,
  `name` varchar(100) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `department_name` varchar(255) DEFAULT NULL,
  `department_id` bigint(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- 表的结构 `personnel_projects`
--

CREATE TABLE IF NOT EXISTS `personnel_projects` (
  `id` int(11) NOT NULL,
  `personnel_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `department_ids` varchar(255) DEFAULT NULL,
  `position` varchar(100) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` enum('active','inactive','completed','on_hold','cancelled') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

--
-- 转存表中的数据 `personnel_projects`
--

INSERT INTO `personnel_projects` (`id`, `personnel_id`, `company_id`, `project_id`, `department_ids`, `position`, `start_date`, `end_date`, `status`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 1, '1,2', '项目经理', '2024-01-01', '2024-12-31', 'active', '2025-08-09 19:50:23', '2025-08-09 19:50:23');

-- --------------------------------------------------------

--
-- 表的结构 `personnel_project_users`
--

CREATE TABLE IF NOT EXISTS `personnel_project_users` (
  `id` int(11) NOT NULL,
  `personnel_id` int(11) NOT NULL,
  `project_user_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- 转存表中的数据 `personnel_project_users`
--

INSERT INTO `personnel_project_users` (`id`, `personnel_id`, `project_user_id`, `created_at`) VALUES
(1, 5, 6, '2025-08-09 16:48:16'),
(2, 4, 7, '2025-08-10 04:03:39'),
(3, 5, 8, '2025-08-10 04:07:31'),
(4, 21, 9, '2025-08-12 06:13:15'),
(5, 21, 10, '2025-08-12 06:13:15'),
(6, 24, 11, '2025-08-12 06:14:53'),
(7, 24, 12, '2025-08-12 06:17:12'),
(8, 24, 13, '2025-08-12 06:19:18'),
(9, 21, 14, '2025-08-12 08:53:53'),
(10, 52, 15, '2025-08-12 13:34:58'),
(11, 52, 16, '2025-08-12 13:34:58'),
(12, 52, 17, '2025-08-13 08:02:06'),
(13, 21, 18, '2025-08-14 03:47:15'),
(14, 25, 19, '2025-08-14 06:20:10'),
(15, 25, 20, '2025-08-14 06:20:10'),
(16, 25, 21, '2025-08-14 06:20:10'),
(17, 24, 22, '2025-08-19 06:48:07');

-- --------------------------------------------------------

--
-- 表的结构 `projects`
--

CREATE TABLE IF NOT EXISTS `projects` (
  `id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `code` varchar(50) NOT NULL,
  `description` text,
  `location` varchar(255) NOT NULL,
  `hotel_id` int(11) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` enum('active','inactive','completed') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `arrival_airport` varchar(255) DEFAULT NULL COMMENT '机场1',
  `arrival_railway_station` varchar(255) DEFAULT NULL COMMENT '高铁站1',
  `departure_airport` varchar(255) DEFAULT NULL COMMENT '机场2',
  `departure_railway_station` varchar(255) DEFAULT NULL COMMENT '高铁站2'
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

--
-- 转存表中的数据 `projects`
--

INSERT INTO `projects` (`id`, `company_id`, `name`, `code`, `description`, `location`, `hotel_id`, `start_date`, `end_date`, `status`, `created_at`, `arrival_airport`, `arrival_railway_station`, `departure_airport`, `departure_railway_station`) VALUES
(1, 2, 'AGA Onederful Live 2024 广州站', 'ALI20241201000001', 'AGA江海迦巡回演唱会广州站', '广州体育馆1号馆', 2, '2024-12-15', '2024-12-20', 'active', '2025-08-09 13:25:01', NULL, NULL, NULL, NULL),
(2, 3, '陈奕迅FEAR and DREAMS世界巡回演唱会 广州站', 'TEN20241201000002', '陈奕迅巡回演唱会广州站', '宝能国际演艺中心', 1, '2024-12-10', '2024-12-15', 'active', '2025-08-09 13:25:01', NULL, NULL, NULL, NULL),
(3, 1, '陈慧娴 The Fabulous 40 Priscilla LIVE 成都站', 'BAI20241201000003', '陈慧娴巡回演唱会成都站', '五粮液体育中心体育馆', NULL, '2025-07-28', '2025-08-05', 'active', '2025-08-09 13:25:01', '成都天府T1', '', '', ''),
(4, 2, 'AGA Onederful Live 2024 深圳站', 'AGA20250809140353', 'AGA Onederful Live 2024 深圳站', '深圳湾春茧体育馆', 4, '2025-08-07', '2025-08-23', 'active', '2025-08-09 14:03:53', '深圳宝安机场T2', '深圳北站', '广州白云机场T1', '广州南站'),
(7, 2, 'AGA Onederful Live 2024 佛山站', 'TEST001', 'AGA Onederful Live 2024 佛山站', '佛山国际体育文化演艺中心', 1, '2024-01-01', '2024-12-31', 'active', '2025-08-09 19:50:23', NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- 表的结构 `project_departments`
--

CREATE TABLE IF NOT EXISTS `project_departments` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `department_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- 转存表中的数据 `project_departments`
--

INSERT INTO `project_departments` (`id`, `project_id`, `department_id`, `created_at`) VALUES
(1, 1, 1, '2025-08-10 08:16:56'),
(2, 1, 2, '2025-08-10 08:16:56'),
(3, 1, 9, '2025-08-10 08:16:56'),
(4, 1, 10, '2025-08-10 08:16:56'),
(5, 1, 11, '2025-08-10 08:16:56'),
(6, 1, 12, '2025-08-10 08:16:56'),
(7, 1, 13, '2025-08-10 08:16:56'),
(8, 1, 14, '2025-08-10 08:16:56');

-- --------------------------------------------------------

--
-- 表的结构 `project_department_personnel`
--

CREATE TABLE IF NOT EXISTS `project_department_personnel` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `department_id` int(11) NOT NULL,
  `personnel_id` int(11) NOT NULL,
  `position` varchar(100) DEFAULT NULL,
  `join_date` date DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

--
-- 转存表中的数据 `project_department_personnel`
--

INSERT INTO `project_department_personnel` (`id`, `project_id`, `department_id`, `personnel_id`, `position`, `join_date`, `status`, `created_at`) VALUES
(298, 1, 9, 52, '艺人经纪人', NULL, 'active', '2025-08-14 07:35:12'),
(309, 4, 21, 59, '艺人发型助理', NULL, 'active', '2025-08-19 07:05:00'),
(311, 4, 21, 57, '艺人化妆助理', NULL, 'active', '2025-08-19 07:07:31'),
(310, 4, 21, 56, '艺人化妆', NULL, 'active', '2025-08-19 07:07:12'),
(308, 4, 22, 55, '艺人服装', NULL, 'active', '2025-08-19 07:02:09'),
(307, 4, 17, 54, '艺人助理', NULL, 'active', '2025-08-19 07:01:09'),
(240, 7, 61, 21, '', NULL, 'active', '2025-08-13 03:46:19'),
(86, 3, 39, 21, '', NULL, 'active', '2025-08-12 09:54:18'),
(85, 2, 34, 21, '', NULL, 'active', '2025-08-12 09:54:18'),
(312, 4, 21, 58, '艺人发型', NULL, 'active', '2025-08-19 07:08:21'),
(68, 2, 31, 24, '', NULL, 'active', '2025-08-12 06:18:57'),
(69, 3, 39, 24, '', NULL, 'active', '2025-08-12 06:18:57'),
(306, 4, 17, 53, '监制', NULL, 'active', '2025-08-19 07:00:43'),
(237, 7, 26, 22, '', NULL, 'active', '2025-08-13 03:45:03'),
(236, 7, 26, 23, '', NULL, 'active', '2025-08-13 03:33:22'),
(83, 1, 9, 21, '', NULL, 'active', '2025-08-12 09:54:18'),
(238, 7, 61, 24, '', NULL, 'active', '2025-08-13 03:46:02'),
(239, 7, 61, 25, '', NULL, 'active', '2025-08-13 03:46:11'),
(215, 7, 26, 26, '', NULL, 'active', '2025-08-12 13:46:36'),
(89, 1, 1, 27, NULL, NULL, 'active', '2025-08-12 13:05:07'),
(90, 1, 1, 28, NULL, NULL, 'active', '2025-08-12 13:05:07'),
(91, 1, 1, 29, NULL, NULL, 'active', '2025-08-12 13:05:07'),
(92, 1, 1, 30, NULL, NULL, 'active', '2025-08-12 13:05:07'),
(93, 1, 1, 31, NULL, NULL, 'active', '2025-08-12 13:05:07'),
(305, 4, 62, 51, '艺人', NULL, 'active', '2025-08-19 06:57:35'),
(322, 4, 63, 63, '嘉宾经纪人', NULL, 'active', '2025-08-19 07:13:30'),
(232, 1, 2, 51, '', NULL, 'active', '2025-08-13 02:42:34'),
(297, 7, 25, 52, '艺人经纪人', NULL, 'active', '2025-08-14 07:35:12'),
(299, 7, 25, 53, '音乐总监', NULL, 'active', '2025-08-14 08:05:00'),
(116, 7, 23, 54, '', NULL, 'active', '2025-08-12 13:32:05'),
(117, 7, 25, 55, '', NULL, 'active', '2025-08-12 13:32:05'),
(118, 7, 23, 56, '', NULL, 'active', '2025-08-12 13:32:05'),
(119, 7, 30, 57, '', NULL, 'active', '2025-08-12 13:32:05'),
(120, 7, 23, 58, '', NULL, 'active', '2025-08-12 13:32:05'),
(121, 7, 23, 59, '', NULL, 'active', '2025-08-12 13:32:05'),
(122, 7, 25, 60, '', NULL, 'active', '2025-08-12 13:32:05'),
(234, 7, 60, 61, '艺人', NULL, 'active', '2025-08-13 03:04:42'),
(124, 7, 23, 62, '', NULL, 'active', '2025-08-12 13:32:05'),
(125, 7, 23, 63, '', NULL, 'active', '2025-08-12 13:32:05'),
(126, 7, 23, 64, '', NULL, 'active', '2025-08-12 13:32:05'),
(127, 7, 24, 65, '', NULL, 'active', '2025-08-12 13:32:05'),
(128, 7, 24, 66, '', NULL, 'active', '2025-08-12 13:32:05'),
(129, 7, 24, 67, '', NULL, 'active', '2025-08-12 13:32:05'),
(130, 7, 23, 68, '', NULL, 'active', '2025-08-12 13:32:05'),
(131, 7, 23, 69, '', NULL, 'active', '2025-08-12 13:32:05'),
(132, 7, 24, 70, '', NULL, 'active', '2025-08-12 13:32:05'),
(133, 7, 23, 71, '', NULL, 'active', '2025-08-12 13:32:05'),
(134, 7, 24, 72, '', NULL, 'active', '2025-08-12 13:32:05'),
(135, 7, 24, 73, '', NULL, 'active', '2025-08-12 13:32:05'),
(136, 7, 23, 74, '', NULL, 'active', '2025-08-12 13:32:05'),
(137, 7, 23, 75, '', NULL, 'active', '2025-08-12 13:32:05'),
(300, 7, 30, 76, '', NULL, 'active', '2025-08-19 06:29:28'),
(139, 7, 23, 77, '', NULL, 'active', '2025-08-12 13:32:05'),
(301, 7, 30, 78, '', NULL, 'active', '2025-08-19 06:29:44'),
(141, 7, 24, 79, '', NULL, 'active', '2025-08-12 13:32:05'),
(142, 7, 24, 80, '', NULL, 'active', '2025-08-12 13:32:05'),
(143, 7, 23, 81, '', NULL, 'active', '2025-08-12 13:32:05'),
(144, 7, 23, 82, '', NULL, 'active', '2025-08-12 13:32:05'),
(145, 7, 23, 83, '', NULL, 'active', '2025-08-12 13:32:05'),
(146, 7, 23, 84, '', NULL, 'active', '2025-08-12 13:32:05'),
(147, 7, 28, 85, '', NULL, 'active', '2025-08-12 13:32:05'),
(148, 7, 23, 86, '', NULL, 'active', '2025-08-12 13:32:05'),
(149, 7, 28, 87, '', NULL, 'active', '2025-08-12 13:32:05'),
(150, 7, 28, 88, '', NULL, 'active', '2025-08-12 13:32:05'),
(151, 7, 28, 89, '', NULL, 'active', '2025-08-12 13:32:05'),
(152, 7, 28, 90, '', NULL, 'active', '2025-08-12 13:32:05'),
(153, 7, 28, 91, '', NULL, 'active', '2025-08-12 13:32:05'),
(154, 7, 28, 92, '', NULL, 'active', '2025-08-12 13:32:05'),
(155, 7, 28, 93, '', NULL, 'active', '2025-08-12 13:32:05'),
(156, 7, 28, 94, '', NULL, 'active', '2025-08-12 13:32:05'),
(157, 7, 28, 95, '', NULL, 'active', '2025-08-12 13:32:05'),
(158, 7, 26, 96, '', NULL, 'active', '2025-08-12 13:32:05'),
(159, 7, 26, 97, '', NULL, 'active', '2025-08-12 13:32:05'),
(242, 7, 59, 51, '艺人', NULL, 'active', '2025-08-13 03:46:54'),
(296, 4, 17, 52, '艺人经纪人', NULL, 'active', '2025-08-14 07:35:12'),
(162, 1, 2, 53, '', NULL, 'active', '2025-08-12 13:33:21'),
(163, 1, 2, 54, '', NULL, 'active', '2025-08-12 13:33:21'),
(164, 1, 2, 55, '', NULL, 'active', '2025-08-12 13:33:21'),
(165, 1, 2, 56, '', NULL, 'active', '2025-08-12 13:33:21'),
(166, 1, 2, 57, '', NULL, 'active', '2025-08-12 13:33:21'),
(167, 1, 2, 58, '', NULL, 'active', '2025-08-12 13:33:21'),
(168, 1, 2, 59, '', NULL, 'active', '2025-08-12 13:33:21'),
(169, 1, 2, 60, '', NULL, 'active', '2025-08-12 13:33:21'),
(170, 1, 2, 22, '', NULL, 'active', '2025-08-12 13:33:21'),
(171, 1, 2, 61, '', NULL, 'active', '2025-08-12 13:33:21'),
(172, 1, 2, 62, '', NULL, 'active', '2025-08-12 13:33:21'),
(173, 1, 2, 63, '', NULL, 'active', '2025-08-12 13:33:21'),
(174, 1, 2, 64, '', NULL, 'active', '2025-08-12 13:33:21'),
(175, 1, 2, 23, '', NULL, 'active', '2025-08-12 13:33:21'),
(176, 1, 2, 21, '', NULL, 'active', '2025-08-12 13:33:21'),
(177, 1, 2, 24, '', NULL, 'active', '2025-08-12 13:33:21'),
(178, 1, 2, 25, '', NULL, 'active', '2025-08-12 13:33:21'),
(179, 1, 2, 65, '', NULL, 'active', '2025-08-12 13:33:21'),
(180, 1, 2, 66, '', NULL, 'active', '2025-08-12 13:33:21'),
(181, 1, 2, 67, '', NULL, 'active', '2025-08-12 13:33:21'),
(182, 1, 2, 68, '', NULL, 'active', '2025-08-12 13:33:21'),
(183, 1, 2, 69, '', NULL, 'active', '2025-08-12 13:33:21'),
(184, 1, 2, 70, '', NULL, 'active', '2025-08-12 13:33:21'),
(185, 1, 2, 71, '', NULL, 'active', '2025-08-12 13:33:21'),
(186, 1, 2, 72, '', NULL, 'active', '2025-08-12 13:33:21'),
(187, 1, 2, 73, '', NULL, 'active', '2025-08-12 13:33:21'),
(188, 1, 2, 74, '', NULL, 'active', '2025-08-12 13:33:21'),
(189, 1, 2, 75, '', NULL, 'active', '2025-08-12 13:33:21'),
(190, 1, 2, 76, '', NULL, 'active', '2025-08-12 13:33:21'),
(191, 1, 2, 77, '', NULL, 'active', '2025-08-12 13:33:21'),
(192, 1, 2, 78, '', NULL, 'active', '2025-08-12 13:33:21'),
(193, 1, 2, 79, '', NULL, 'active', '2025-08-12 13:33:21'),
(194, 1, 2, 80, '', NULL, 'active', '2025-08-12 13:33:21'),
(195, 1, 2, 81, '', NULL, 'active', '2025-08-12 13:33:21'),
(196, 1, 2, 82, '', NULL, 'active', '2025-08-12 13:33:21'),
(197, 1, 2, 83, '', NULL, 'active', '2025-08-12 13:33:21'),
(198, 1, 2, 84, '', NULL, 'active', '2025-08-12 13:33:21'),
(199, 1, 2, 85, '', NULL, 'active', '2025-08-12 13:33:21'),
(200, 1, 2, 86, '', NULL, 'active', '2025-08-12 13:33:21'),
(201, 1, 2, 87, '', NULL, 'active', '2025-08-12 13:33:21'),
(202, 1, 2, 88, '', NULL, 'active', '2025-08-12 13:33:21'),
(203, 1, 2, 89, '', NULL, 'active', '2025-08-12 13:33:21'),
(204, 1, 2, 90, '', NULL, 'active', '2025-08-12 13:33:21'),
(205, 1, 2, 91, '', NULL, 'active', '2025-08-12 13:33:21'),
(206, 1, 2, 92, '', NULL, 'active', '2025-08-12 13:33:21'),
(207, 1, 2, 93, '', NULL, 'active', '2025-08-12 13:33:21'),
(208, 1, 2, 94, '', NULL, 'active', '2025-08-12 13:33:21'),
(209, 1, 2, 95, '', NULL, 'active', '2025-08-12 13:33:21'),
(210, 1, 2, 96, '', NULL, 'active', '2025-08-12 13:33:21'),
(211, 1, 2, 97, '', NULL, 'active', '2025-08-12 13:33:21'),
(212, 1, 2, 26, '', NULL, 'active', '2025-08-12 13:33:21'),
(313, 4, 16, 60, '艺人拍摄', NULL, 'active', '2025-08-19 07:09:46'),
(314, 4, 16, 22, '艺人拍摄', NULL, 'active', '2025-08-19 07:10:52'),
(316, 4, 63, 61, '嘉宾', NULL, 'active', '2025-08-19 07:11:25'),
(319, 4, 63, 62, '嘉宾夫人', NULL, 'active', '2025-08-19 07:12:17'),
(321, 4, 63, 64, '嘉宾妆发', NULL, 'active', '2025-08-19 07:13:22'),
(360, 4, 64, 23, '工程统筹', NULL, 'active', '2025-08-19 07:29:51'),
(363, 4, 64, 21, '艺能制作', NULL, 'active', '2025-08-19 07:30:55'),
(361, 4, 64, 24, '艺能制作', NULL, 'active', '2025-08-19 07:30:15'),
(362, 4, 64, 25, '艺能制作', NULL, 'active', '2025-08-19 07:30:36'),
(323, 4, 16, 65, 'PD', NULL, 'active', '2025-08-19 07:13:57'),
(324, 4, 16, 66, 'PA', NULL, 'active', '2025-08-19 07:14:13'),
(325, 4, 16, 67, 'PA', NULL, 'active', '2025-08-19 07:14:24'),
(326, 4, 16, 68, 'Audio - House', NULL, 'active', '2025-08-19 07:15:07'),
(327, 4, 16, 69, 'Audio - Monitor', NULL, 'active', '2025-08-19 07:15:24'),
(328, 4, 16, 70, 'Audio - Monitor Assistant', NULL, 'active', '2025-08-19 07:15:39'),
(329, 4, 16, 71, 'Lighting', NULL, 'active', '2025-08-19 07:15:57'),
(330, 4, 16, 72, 'Lighting', NULL, 'active', '2025-08-19 07:16:49'),
(331, 4, 16, 73, 'VJ', NULL, 'active', '2025-08-19 07:17:12'),
(332, 4, 16, 74, 'FM 1', NULL, 'active', '2025-08-19 07:17:29'),
(333, 4, 16, 75, 'FM 2', NULL, 'active', '2025-08-19 07:17:46'),
(334, 4, 22, 76, 'Dresser 1', NULL, 'active', '2025-08-19 07:18:16'),
(335, 4, 22, 77, 'Dresser 2', NULL, 'active', '2025-08-19 07:18:32'),
(338, 4, 15, 79, '乐队总监', NULL, 'active', '2025-08-19 07:19:44'),
(339, 4, 15, 80, 'keyboards', NULL, 'active', '2025-08-19 07:20:13'),
(340, 4, 15, 81, '吉他手 1', NULL, 'active', '2025-08-19 07:20:38'),
(341, 4, 15, 82, '吉他手 2', NULL, 'active', '2025-08-19 07:21:33'),
(342, 4, 15, 83, 'Bassist', NULL, 'active', '2025-08-19 07:21:50'),
(343, 4, 15, 84, '鼓手', NULL, 'active', '2025-08-19 07:22:08'),
(344, 4, 19, 85, '和声 1', NULL, 'active', '2025-08-19 07:23:28'),
(345, 4, 19, 86, '和声 2', NULL, 'active', '2025-08-19 07:23:47'),
(346, 4, 20, 87, '排舞老师', NULL, 'active', '2025-08-19 07:24:04'),
(347, 4, 20, 88, '舞者 1', NULL, 'active', '2025-08-19 07:24:33'),
(348, 4, 20, 89, '舞者 2', NULL, 'active', '2025-08-19 07:24:50'),
(349, 4, 20, 90, '舞者 3', NULL, 'active', '2025-08-19 07:25:17'),
(350, 4, 20, 91, '舞者 4', NULL, 'active', '2025-08-19 07:25:46'),
(354, 4, 20, 93, '舞者 6', NULL, 'active', '2025-08-19 07:27:09'),
(355, 4, 20, 94, '舞者 7', NULL, 'active', '2025-08-19 07:27:37'),
(356, 4, 20, 95, '舞者 8', NULL, 'active', '2025-08-19 07:27:59'),
(357, 4, 18, 96, '巡演统筹', NULL, 'active', '2025-08-19 07:28:27'),
(358, 4, 18, 97, '巡演统筹', NULL, 'active', '2025-08-19 07:29:08'),
(359, 4, 64, 26, '主办方', NULL, 'active', '2025-08-19 07:29:22'),
(337, 4, 22, 78, 'Dresser 3', NULL, 'active', '2025-08-19 07:19:06'),
(353, 4, 20, 92, '舞者 5', NULL, 'active', '2025-08-19 07:26:35');

-- --------------------------------------------------------

--
-- 表的结构 `project_hotels`
--

CREATE TABLE IF NOT EXISTS `project_hotels` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `hotel_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- 转存表中的数据 `project_hotels`
--

INSERT INTO `project_hotels` (`id`, `project_id`, `hotel_id`, `created_at`) VALUES
(5, 7, 1, '2025-08-20 05:57:22'),
(7, 1, 3, '2025-08-20 05:57:37'),
(8, 1, 2, '2025-08-20 05:57:37'),
(13, 4, 4, '2025-08-27 07:16:41'),
(14, 4, 5, '2025-08-27 07:16:41');

-- --------------------------------------------------------

--
-- 表的结构 `project_users`
--

CREATE TABLE IF NOT EXISTS `project_users` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `username` varchar(100) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `display_name` varchar(100) DEFAULT NULL,
  `role` enum('admin','user') DEFAULT 'user',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

--
-- 转存表中的数据 `project_users`
--

INSERT INTO `project_users` (`id`, `project_id`, `username`, `email`, `phone`, `password`, `display_name`, `role`, `is_active`, `created_at`, `updated_at`) VALUES
(10, 2, '袁星_TEN20241201000002', '袁星ten20241201000002@example.com', '13800000010', '$2y$10$v4O.zPWCL12y.CshxCJSbOdKk2Y7bd7rf0GXGfY.VRUNSAznwZ1iW', '袁星', 'user', 1, '2025-08-12 06:13:15', NULL),
(9, 1, '袁星_ALI20241201000001', '袁星ali20241201000001@example.com', '13800000009', '$2y$10$4m7q1ol4wXo45aTpDDkcgOr0UpJegz1eEwg4PlLgbcg1.tQKuCtPC', '袁星', 'user', 0, '2025-08-12 06:13:15', NULL),
(19, 1, '谭潇_ALI20241201000001', '谭潇ali20241201000001@example.com', '13800000019', '$2y$10$ZnqMw0tNnTncp8fm1VKvzO5zk/Ger1lF6FzjlNHjZNKpb7UaO99vK', '谭潇', 'user', 1, '2025-08-14 06:20:10', NULL),
(17, 4, '曾欣婷_AGA20250809140353', '曾欣婷aga20250809140353@example.com', '13800000017', '$2y$10$g9H0qIkTRgWGedf1A0W7deHIXgfz34wrIEL0FsIpvVBPAfq1sse/i', '曾欣婷', 'user', 1, '2025-08-13 08:02:06', NULL),
(18, 4, 'yinsen', 'aga20250809140353@example.com', '13800000018', '$2y$10$QFCcMc5tWXicZxsBy1hklO593licaZ/oUPJzSM5X/Ab8DVG/9YZbW', '袁星', 'admin', 1, '2025-08-14 03:47:15', '2025-08-27 13:13:26'),
(11, 7, '何欣昕_TEST001', '何欣昕test001@example.com', '13800000011', '$2y$10$bu/kXKxDkRAckXsC08oC3OpVrp7/eVJKzoZnhcMcqSYosIY8yYZSe', '何欣昕', 'user', 1, '2025-08-12 06:14:53', NULL),
(12, 2, '何欣昕_TEN20241201000002', '何欣昕ten20241201000002@example.com', '13800000012', '$2y$10$6xLQqDBgX2rvpSa5rPAcg.j0gCatzMI4nirEdiELScUL0T/t2okBC', '何欣昕', 'user', 1, '2025-08-12 06:17:12', NULL),
(13, 3, '何欣昕_BAI20241201000003', '何欣昕bai20241201000003@example.com', '13800000013', '$2y$10$mLHlBPzMe.PC/C6r2.Vr8OZqB8ZOVcxmzBuSzMO/Zzi91imIYkY/K', '何欣昕', 'admin', 1, '2025-08-12 06:19:18', '2025-08-28 08:11:39'),
(14, 7, '袁星_TEST001', '袁星test001@example.com', '13800000014', '$2y$10$kCFLtIgoWNrN0ofJJYeDT.DtFZvWGwvSvIvWqEtUeRRBQu7umW3k.', '袁星', 'admin', 1, '2025-08-12 08:53:53', NULL),
(15, 7, '曾欣婷_TEST001', '曾欣婷test001@example.com', '13800000015', '$2y$10$L3KXyMm9NZLKr/vSxunJU.qMODl5awRSVB359.WtAS.97fBR1Owt.', '曾欣婷', 'user', 1, '2025-08-12 13:34:58', NULL),
(16, 1, '曾欣婷_ALI20241201000001', '曾欣婷ali20241201000001@example.com', '13800000016', '$2y$10$sRoUwxoIjtu.ZPBYdru4g.M6ji6I7j87e6yfPJo6lZ0LJcz7pddoG', '曾欣婷', 'user', 1, '2025-08-12 13:34:58', NULL),
(20, 4, '谭潇_AGA20250809140353', '谭潇aga20250809140353@example.com', '13800000020', '$2y$10$pc5rCiMWxeymfcj7QxMvT.bfpGB4bXszHfGdE.rSTHtWi.QJJ4t2m', '谭潇', 'user', 1, '2025-08-14 06:20:10', NULL),
(21, 7, '谭潇_TEST001', '谭潇test001@example.com', '13800000021', '$2y$10$FJ4kuFh3gGD7vOFM4IklF.C53Ok4lliZ/As/IffPVDUxXGmbb5v0W', '谭潇', 'admin', 1, '2025-08-14 06:20:10', NULL),
(22, 4, '何欣昕_AGA20250809140353', '何欣昕aga20250809140353@example.com', '13800000022', '$2y$10$3Pco5ZZc91Ftp75/9GS4LudxVLjsEpSxQd6KpS8Fhq6zylWA8MVam', '何欣昕', 'admin', 1, '2025-08-19 06:48:07', '2025-08-19 08:52:10');

-- --------------------------------------------------------

--
-- 表的结构 `site_config`
--

CREATE TABLE IF NOT EXISTS `site_config` (
  `id` int(11) NOT NULL,
  `config_key` varchar(100) NOT NULL,
  `config_value` text,
  `config_type` varchar(50) DEFAULT 'string',
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=MyISAM AUTO_INCREMENT=617 DEFAULT CHARSET=utf8;

--
-- 转存表中的数据 `site_config`
--

INSERT INTO `site_config` (`id`, `config_key`, `config_value`, `config_type`, `description`, `created_at`, `updated_at`) VALUES
(608, 'site_name', '测试名称_1756301630', 'string', '网站名称', '2025-08-27 13:29:02', '2025-08-28 08:11:40'),
(609, 'site_url', 'https://livegig.cn/', 'string', '网站访问地址', '2025-08-27 13:29:02', '2025-08-28 08:11:40'),
(610, 'frontend_title', '测试前端标题', 'string', '前端网页标题', '2025-08-27 13:29:02', '2025-08-28 08:11:40'),
(611, 'admin_title', '测试后台标题', 'string', '管理后台标题', '2025-08-27 13:29:02', '2025-08-28 08:11:40'),
(612, 'meta_description', '测试描述', 'string', '网站描述', '2025-08-27 13:29:02', '2025-08-28 08:11:40'),
(613, 'meta_keywords', '测试,关键词', 'string', '网站关键词', '2025-08-27 13:29:02', '2025-08-28 08:11:40'),
(614, 'footer_text', '测试页脚 © 2025', 'string', '页脚文本', '2025-08-27 13:29:02', '2025-08-28 08:11:40'),
(615, 'contact_email', 'test@example.com', 'string', '联系邮箱', '2025-08-27 13:29:02', '2025-08-28 08:11:40'),
(616, 'contact_phone', '123-456-7890', 'string', '联系电话', '2025-08-27 13:29:02', '2025-08-28 08:11:40');

-- --------------------------------------------------------

--
-- 表的结构 `transportation_fleet_assignments`
--

CREATE TABLE IF NOT EXISTS `transportation_fleet_assignments` (
  `id` int(11) NOT NULL,
  `transportation_report_id` int(11) NOT NULL,
  `fleet_id` int(11) NOT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB AUTO_INCREMENT=85 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- 转存表中的数据 `transportation_fleet_assignments`
--

INSERT INTO `transportation_fleet_assignments` (`id`, `transportation_report_id`, `fleet_id`, `assigned_at`) VALUES
(14, 12, 9, '2025-08-14 09:24:37'),
(15, 13, 9, '2025-08-14 09:24:37'),
(36, 73, 15, '2025-08-21 06:29:27'),
(37, 73, 16, '2025-08-21 06:29:30'),
(38, 73, 17, '2025-08-21 06:29:33'),
(39, 72, 18, '2025-08-21 06:30:13'),
(41, 63, 20, '2025-08-21 06:46:07'),
(46, 61, 18, '2025-08-21 08:22:31'),
(51, 97, 20, '2025-08-25 08:40:47'),
(52, 94, 15, '2025-08-25 09:04:31'),
(53, 94, 16, '2025-08-25 09:04:34'),
(54, 94, 17, '2025-08-25 09:04:39'),
(55, 83, 15, '2025-08-25 09:05:37'),
(56, 83, 16, '2025-08-25 09:05:40'),
(57, 83, 17, '2025-08-25 09:05:44'),
(58, 81, 22, '2025-08-25 09:46:01'),
(59, 84, 21, '2025-08-25 09:46:20'),
(60, 84, 22, '2025-08-25 09:46:27'),
(61, 88, 21, '2025-08-25 09:46:36'),
(62, 93, 15, '2025-08-25 09:46:55'),
(63, 93, 16, '2025-08-25 09:47:00'),
(64, 93, 17, '2025-08-25 09:47:05'),
(65, 95, 18, '2025-08-25 09:47:17'),
(66, 96, 18, '2025-08-25 09:47:35'),
(67, 89, 21, '2025-08-25 09:55:13'),
(68, 89, 22, '2025-08-25 09:55:18'),
(69, 90, 21, '2025-08-25 09:56:32'),
(70, 90, 22, '2025-08-25 09:56:37'),
(71, 91, 19, '2025-08-25 09:58:50'),
(72, 92, 20, '2025-08-25 09:59:07'),
(73, 86, 20, '2025-08-25 09:59:30'),
(74, 87, 20, '2025-08-25 09:59:43'),
(75, 85, 21, '2025-08-25 09:59:58'),
(76, 82, 20, '2025-08-25 10:00:11'),
(77, 80, 19, '2025-08-25 10:00:20'),
(78, 58, 15, '2025-08-26 09:45:18'),
(80, 60, 15, '2025-08-26 09:47:26'),
(81, 62, 15, '2025-08-26 09:48:42'),
(82, 103, 15, '2025-08-28 08:45:40'),
(83, 105, 18, '2025-08-28 08:48:45'),
(84, 104, 16, '2025-08-28 08:49:20');

-- --------------------------------------------------------

--
-- 表的结构 `transportation_passengers`
--

CREATE TABLE IF NOT EXISTS `transportation_passengers` (
  `id` int(11) NOT NULL,
  `transportation_report_id` int(11) NOT NULL,
  `personnel_id` int(11) NOT NULL
) ENGINE=MyISAM AUTO_INCREMENT=292 DEFAULT CHARSET=utf8;

--
-- 转存表中的数据 `transportation_passengers`
--

INSERT INTO `transportation_passengers` (`id`, `transportation_report_id`, `personnel_id`) VALUES
(1, 57, 84),
(2, 57, 66),
(3, 57, 94),
(4, 58, 68),
(5, 58, 70),
(6, 58, 65),
(7, 58, 67),
(8, 58, 69),
(13, 60, 71),
(14, 60, 72),
(15, 61, 68),
(16, 61, 70),
(17, 61, 65),
(18, 61, 67),
(19, 61, 69),
(20, 62, 66),
(21, 62, 74),
(22, 62, 75),
(23, 62, 73),
(24, 63, 71),
(25, 63, 72),
(26, 68, 84),
(27, 68, 94),
(28, 69, 94),
(29, 69, 95),
(30, 69, 88),
(31, 69, 93),
(32, 69, 87),
(33, 70, 24),
(34, 70, 97),
(35, 70, 23),
(36, 70, 25),
(40, 72, 66),
(41, 72, 74),
(42, 72, 75),
(43, 72, 73),
(44, 72, 65),
(45, 72, 67),
(46, 73, 56),
(47, 73, 52),
(48, 73, 53),
(49, 73, 59),
(50, 73, 51),
(51, 73, 54),
(52, 73, 58),
(53, 73, 57),
(54, 74, 84),
(55, 74, 94),
(56, 74, 77),
(57, 74, 79),
(58, 74, 97),
(59, 74, 91),
(60, 74, 95),
(61, 74, 92),
(62, 74, 88),
(63, 74, 86),
(64, 74, 80),
(65, 74, 90),
(66, 74, 78),
(67, 74, 81),
(68, 74, 93),
(69, 74, 76),
(70, 74, 89),
(71, 74, 85),
(72, 74, 96),
(73, 74, 87),
(74, 74, 82),
(75, 74, 83),
(76, 75, 84),
(77, 75, 77),
(78, 75, 55),
(79, 75, 79),
(80, 75, 80),
(81, 75, 78),
(82, 75, 81),
(83, 75, 76),
(84, 75, 82),
(85, 75, 83),
(86, 76, 66),
(87, 76, 74),
(88, 76, 75),
(89, 76, 71),
(90, 76, 73),
(91, 76, 72),
(92, 76, 68),
(93, 76, 70),
(94, 76, 65),
(95, 76, 67),
(96, 77, 84),
(97, 77, 94),
(98, 77, 77),
(99, 77, 79),
(100, 77, 97),
(101, 77, 91),
(102, 77, 95),
(103, 77, 92),
(104, 77, 88),
(105, 77, 86),
(106, 77, 80),
(107, 77, 90),
(108, 77, 78),
(109, 77, 81),
(110, 77, 93),
(111, 77, 76),
(112, 77, 89),
(113, 77, 85),
(114, 77, 96),
(115, 77, 87),
(116, 77, 82),
(117, 77, 83),
(118, 78, 97),
(119, 78, 51),
(120, 78, 96),
(121, 79, 24),
(122, 79, 97),
(123, 79, 26),
(124, 79, 52),
(125, 79, 53),
(126, 79, 23),
(127, 79, 21),
(128, 79, 25),
(129, 79, 96),
(130, 79, 54),
(131, 80, 84),
(132, 80, 79),
(133, 81, 86),
(134, 81, 80),
(135, 81, 81),
(136, 81, 85),
(137, 81, 96),
(138, 81, 82),
(139, 81, 83),
(140, 82, 71),
(141, 82, 72),
(142, 83, 56),
(143, 83, 52),
(144, 83, 53),
(145, 83, 59),
(146, 83, 51),
(147, 83, 54),
(148, 83, 58),
(149, 83, 57),
(150, 84, 84),
(151, 84, 94),
(152, 84, 77),
(153, 84, 79),
(154, 84, 97),
(155, 84, 91),
(156, 84, 95),
(157, 84, 92),
(158, 84, 88),
(159, 84, 86),
(160, 84, 80),
(161, 84, 90),
(162, 84, 78),
(163, 84, 81),
(164, 84, 93),
(165, 84, 76),
(166, 84, 89),
(167, 84, 85),
(168, 84, 96),
(169, 84, 87),
(170, 84, 82),
(171, 84, 83),
(172, 85, 66),
(173, 85, 74),
(174, 85, 75),
(175, 85, 71),
(176, 85, 73),
(177, 85, 72),
(178, 85, 68),
(179, 85, 70),
(180, 85, 65),
(181, 85, 67),
(182, 85, 69),
(183, 86, 71),
(184, 86, 72),
(185, 87, 68),
(186, 87, 70),
(187, 87, 69),
(188, 88, 66),
(189, 88, 74),
(190, 88, 75),
(191, 88, 73),
(192, 88, 65),
(193, 88, 67),
(194, 89, 94),
(195, 89, 91),
(196, 89, 95),
(197, 89, 92),
(198, 89, 88),
(199, 89, 90),
(200, 89, 93),
(201, 89, 89),
(202, 89, 87),
(203, 90, 84),
(204, 90, 79),
(205, 90, 86),
(206, 90, 80),
(207, 90, 81),
(208, 90, 85),
(209, 90, 82),
(210, 90, 83),
(211, 91, 71),
(212, 91, 72),
(213, 92, 77),
(214, 92, 78),
(215, 92, 76),
(216, 93, 56),
(217, 93, 52),
(218, 93, 53),
(219, 93, 59),
(220, 93, 51),
(221, 93, 54),
(222, 93, 58),
(223, 93, 57),
(224, 94, 56),
(225, 94, 52),
(226, 94, 53),
(227, 94, 59),
(228, 94, 51),
(229, 94, 54),
(230, 94, 58),
(231, 94, 57),
(232, 95, 64),
(233, 95, 61),
(234, 95, 63),
(235, 95, 62),
(236, 96, 64),
(237, 96, 61),
(238, 96, 63),
(239, 96, 62),
(240, 97, 79),
(241, 97, 65),
(242, 99, 84),
(243, 99, 66),
(244, 99, 94),
(245, 99, 74),
(246, 99, 77),
(247, 99, 56),
(248, 99, 75),
(249, 99, 97),
(250, 99, 91),
(251, 99, 71),
(252, 99, 95),
(253, 99, 92),
(254, 99, 73),
(255, 99, 88),
(256, 99, 86),
(257, 99, 80),
(258, 99, 59),
(259, 99, 90),
(260, 99, 78),
(261, 99, 72),
(262, 99, 81),
(263, 99, 93),
(264, 99, 76),
(265, 99, 89),
(266, 99, 85),
(267, 99, 68),
(268, 99, 96),
(269, 99, 87),
(270, 99, 70),
(271, 99, 67),
(272, 99, 82),
(273, 99, 69),
(274, 99, 83),
(275, 99, 58),
(276, 99, 57),
(282, 102, 52),
(283, 102, 53),
(284, 102, 54),
(285, 104, 66),
(286, 104, 74),
(287, 104, 75),
(288, 104, 73),
(289, 105, 68),
(290, 105, 70),
(291, 105, 69);

-- --------------------------------------------------------

--
-- 表的结构 `transportation_reports`
--

CREATE TABLE IF NOT EXISTS `transportation_reports` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `personnel_id` int(11) NOT NULL,
  `parent_transport_id` int(11) DEFAULT NULL,
  `travel_date` date NOT NULL,
  `trip_time` varchar(10) DEFAULT NULL,
  `travel_type` enum('接机/站','送机/站','混合交通安排（自定义）','点对点','接站','送站','混合交通安排') NOT NULL,
  `departure_location` varchar(255) DEFAULT NULL,
  `destination_location` varchar(255) DEFAULT NULL,
  `start_location` varchar(255) DEFAULT NULL,
  `end_location` varchar(255) DEFAULT NULL,
  `departure_time` time DEFAULT NULL,
  `arrival_time` time DEFAULT NULL,
  `vehicle_type` varchar(100) DEFAULT NULL,
  `passenger_count` int(11) DEFAULT '1',
  `contact_phone` varchar(50) DEFAULT NULL,
  `special_requirements` text,
  `vehicle_requirements` text,
  `status` enum('pending','confirmed','cancelled') DEFAULT 'pending',
  `reported_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `fleet_number` varchar(50) DEFAULT NULL COMMENT '车队编号',
  `driver_name` varchar(100) DEFAULT NULL COMMENT '驾驶员姓名',
  `driver_phone` varchar(20) DEFAULT NULL COMMENT '驾驶员电话',
  `license_plate` varchar(20) DEFAULT NULL COMMENT '车牌号码',
  `vehicle_model` varchar(50) DEFAULT NULL COMMENT '具体车型',
  `cost` decimal(10,2) DEFAULT '0.00',
  `description` text
) ENGINE=MyISAM AUTO_INCREMENT=106 DEFAULT CHARSET=utf8;

--
-- 转存表中的数据 `transportation_reports`
--

INSERT INTO `transportation_reports` (`id`, `project_id`, `personnel_id`, `parent_transport_id`, `travel_date`, `trip_time`, `travel_type`, `departure_location`, `destination_location`, `start_location`, `end_location`, `departure_time`, `arrival_time`, `vehicle_type`, `passenger_count`, `contact_phone`, `special_requirements`, `vehicle_requirements`, `status`, `reported_by`, `created_at`, `updated_at`, `fleet_number`, `driver_name`, `driver_phone`, `license_plate`, `vehicle_model`, `cost`, `description`) VALUES
(58, 4, 68, NULL, '2024-07-25', NULL, '点对点', '深圳深铁皇冠假日酒店', '深圳湾春茧体育馆', NULL, NULL, '15:45:00', '00:00:00', NULL, 5, '', '', NULL, 'confirmed', 22, '2025-08-19 07:44:33', '2025-08-21 06:36:00', NULL, NULL, NULL, NULL, NULL, '0.00', NULL),
(60, 4, 71, NULL, '2024-07-25', NULL, '点对点', '深圳深铁皇冠假日酒店', '深圳湾春茧体育馆', NULL, NULL, '17:45:00', '00:00:00', NULL, 2, '', '', NULL, 'confirmed', 22, '2025-08-19 08:09:12', '2025-08-21 09:46:50', NULL, NULL, NULL, NULL, NULL, '0.00', NULL),
(61, 4, 68, NULL, '2024-07-25', NULL, '点对点', '深圳湾春茧体育馆', '深圳深铁皇冠假日酒店', NULL, NULL, '20:00:00', '00:00:00', NULL, 5, '', '', NULL, 'confirmed', 22, '2025-08-19 08:09:12', '2025-08-21 09:47:05', NULL, NULL, NULL, NULL, NULL, '0.00', NULL),
(62, 4, 66, NULL, '2024-07-25', NULL, '点对点', '深圳湾春茧体育馆', '深圳深铁皇冠假日酒店', NULL, NULL, '20:00:00', '00:00:00', NULL, 4, '', '', NULL, 'confirmed', 22, '2025-08-19 08:09:12', '2025-08-21 09:47:08', NULL, NULL, NULL, NULL, NULL, '0.00', NULL),
(63, 4, 71, NULL, '2024-07-26', NULL, '点对点', '深圳湾春茧体育馆', '深圳深铁皇冠假日酒店', NULL, NULL, '02:00:00', '00:00:00', NULL, 2, '', '通宵使用', NULL, 'confirmed', 22, '2025-08-19 08:09:12', '2025-08-21 09:47:02', NULL, NULL, NULL, NULL, NULL, '0.00', NULL),
(72, 4, 66, NULL, '2024-07-26', NULL, '点对点', '深圳深铁皇冠假日酒店', '深圳湾春茧体育馆', NULL, NULL, '10:45:00', NULL, NULL, 6, NULL, NULL, NULL, 'confirmed', 22, '2025-08-21 03:58:40', '2025-08-21 09:46:56', NULL, NULL, NULL, NULL, NULL, '0.00', NULL),
(73, 4, 56, NULL, '2024-07-26', NULL, '点对点', '深圳深铁皇冠假日酒店', '深圳湾春茧体育馆', NULL, NULL, '16:00:00', NULL, NULL, 8, NULL, NULL, NULL, 'confirmed', 22, '2025-08-21 06:25:51', '2025-08-21 06:27:28', NULL, NULL, NULL, NULL, NULL, '0.00', NULL),
(82, 4, 71, NULL, '2024-07-26', NULL, '点对点', '深圳深铁皇冠假日酒店', '深圳湾春茧体育馆', NULL, NULL, '15:00:00', NULL, NULL, 2, NULL, NULL, '{"van":{"type":"van","quantity":1}}', 'pending', 22, '2025-08-22 07:49:28', '2025-08-22 07:49:28', NULL, NULL, NULL, NULL, NULL, '0.00', NULL),
(81, 4, 86, NULL, '2024-07-26', NULL, '点对点', '深圳深铁皇冠假日酒店', '深圳湾春茧体育馆', NULL, NULL, '15:00:00', NULL, NULL, 7, NULL, NULL, '{"minibus":{"type":"minibus","quantity":1}}', 'pending', 22, '2025-08-22 07:48:44', '2025-08-22 07:48:44', NULL, NULL, NULL, NULL, NULL, '0.00', NULL),
(80, 4, 84, NULL, '2024-07-26', NULL, '点对点', '深圳深铁皇冠假日酒店', '深圳湾春茧体育馆', NULL, NULL, '14:00:00', NULL, NULL, 2, NULL, NULL, '{"van":{"type":"van","quantity":1}}', 'pending', 22, '2025-08-22 07:47:22', '2025-08-22 07:47:22', NULL, NULL, NULL, NULL, NULL, '0.00', NULL),
(104, 4, 66, NULL, '2024-07-25', NULL, '点对点', '深圳深铁皇冠假日酒店', '深圳湾春茧体育馆', NULL, NULL, '15:45:00', NULL, NULL, 4, NULL, NULL, '{"van":{"type":"van","quantity":1}}', 'pending', 22, '2025-08-28 08:17:42', '2025-08-28 08:17:42', NULL, NULL, NULL, NULL, NULL, '0.00', NULL),
(83, 4, 56, NULL, '2024-07-26', NULL, '点对点', '深圳湾春茧体育馆', '深圳深铁皇冠假日酒店', NULL, NULL, '22:00:00', NULL, NULL, 8, NULL, NULL, '{"van":{"type":"van","quantity":3}}', 'pending', 22, '2025-08-22 07:53:06', '2025-08-22 07:53:06', NULL, NULL, NULL, NULL, NULL, '0.00', NULL),
(84, 4, 84, NULL, '2024-07-26', NULL, '点对点', '深圳湾春茧体育馆', '深圳深铁皇冠假日酒店', NULL, NULL, '22:00:00', NULL, NULL, 22, NULL, NULL, '{"minibus":{"type":"minibus","quantity":2}}', 'confirmed', 22, '2025-08-22 07:54:45', '2025-08-26 06:07:54', NULL, NULL, NULL, NULL, NULL, '0.00', NULL),
(85, 4, 66, NULL, '2024-07-26', NULL, '点对点', '深圳湾春茧体育馆', '深圳深铁皇冠假日酒店', NULL, NULL, '22:00:00', NULL, NULL, 11, NULL, NULL, '{"minibus":{"type":"minibus","quantity":1}}', 'pending', 22, '2025-08-22 07:56:07', '2025-08-22 07:56:07', NULL, NULL, NULL, NULL, NULL, '0.00', NULL),
(86, 4, 71, NULL, '2024-07-27', NULL, '点对点', '深圳湾春茧体育馆', '深圳深铁皇冠假日酒店', NULL, NULL, '02:00:00', '00:00:00', NULL, 2, '', '通宵使用', '{"van":{"type":"van","quantity":1}}', 'pending', 22, '2025-08-22 07:57:43', '2025-08-28 08:03:32', NULL, NULL, NULL, NULL, NULL, '0.00', NULL),
(87, 4, 68, NULL, '2024-07-27', NULL, '点对点', '深圳深铁皇冠假日酒店', '深圳湾春茧体育馆', NULL, NULL, '10:30:00', NULL, NULL, 3, NULL, NULL, '{"van":{"type":"van","quantity":1}}', 'pending', 22, '2025-08-22 07:59:20', '2025-08-22 07:59:20', NULL, NULL, NULL, NULL, NULL, '0.00', NULL),
(88, 4, 66, NULL, '2024-07-27', NULL, '点对点', '深圳深铁皇冠假日酒店', '深圳湾春茧体育馆', NULL, NULL, '12:00:00', NULL, NULL, 6, NULL, NULL, '{"minibus":{"type":"minibus","quantity":1}}', 'pending', 22, '2025-08-22 08:01:11', '2025-08-22 08:01:11', NULL, NULL, NULL, NULL, NULL, '0.00', NULL),
(89, 4, 94, NULL, '2024-07-27', NULL, '点对点', '深圳深铁皇冠假日酒店', '深圳湾春茧体育馆', NULL, NULL, '12:30:00', '00:00:00', NULL, 9, '', '', '{"minibus":{"type":"minibus","quantity":2}}', 'pending', 22, '2025-08-22 08:30:02', '2025-08-22 08:32:50', NULL, NULL, NULL, NULL, NULL, '0.00', NULL),
(90, 4, 84, NULL, '2024-07-27', NULL, '点对点', '深圳深铁皇冠假日酒店', '深圳湾春茧体育馆', NULL, NULL, '13:30:00', '00:00:00', NULL, 8, '', '', '{"minibus":{"type":"minibus","quantity":2}}', 'pending', 22, '2025-08-22 08:30:54', '2025-08-22 08:33:09', NULL, NULL, NULL, NULL, NULL, '0.00', NULL),
(91, 4, 71, NULL, '2024-07-27', NULL, '点对点', '深圳深铁皇冠假日酒店', '深圳湾春茧体育馆', NULL, NULL, '13:00:00', '00:00:00', NULL, 2, '', '', '{"van":{"type":"van","quantity":1}}', 'pending', 22, '2025-08-22 08:31:34', '2025-08-22 08:33:30', NULL, NULL, NULL, NULL, NULL, '0.00', NULL),
(92, 4, 77, NULL, '2024-07-27', NULL, '点对点', '深圳深铁皇冠假日酒店', '深圳湾春茧体育馆', NULL, NULL, '13:00:00', NULL, NULL, 3, NULL, NULL, '{"van":{"type":"van","quantity":1}}', 'pending', 22, '2025-08-22 08:32:16', '2025-08-22 08:32:16', NULL, NULL, NULL, NULL, NULL, '0.00', NULL),
(93, 4, 56, NULL, '2024-07-27', NULL, '点对点', '深圳深铁皇冠假日酒店', '深圳湾春茧体育馆', NULL, NULL, '14:00:00', NULL, NULL, 8, NULL, NULL, '{"van":{"type":"van","quantity":3}}', 'pending', 22, '2025-08-22 08:37:04', '2025-08-22 08:37:04', NULL, NULL, NULL, NULL, NULL, '0.00', NULL),
(94, 4, 56, NULL, '2024-07-27', NULL, '点对点', '深圳湾春茧体育馆', '深圳深铁皇冠假日酒店', NULL, NULL, '22:30:00', '00:00:00', NULL, 8, '', '', '{"van":{"type":"van","quantity":3}}', 'pending', 22, '2025-08-22 08:38:01', '2025-08-22 09:18:22', NULL, NULL, NULL, NULL, NULL, '0.00', NULL),
(95, 4, 64, NULL, '2024-07-27', NULL, '点对点', '深圳深铁皇冠假日酒店', '深圳湾春茧体育馆', NULL, NULL, '15:00:00', NULL, NULL, 4, NULL, NULL, '{"van":{"type":"van","quantity":1}}', 'pending', 22, '2025-08-22 08:55:57', '2025-08-22 08:55:57', NULL, NULL, NULL, NULL, NULL, '0.00', NULL),
(96, 4, 64, NULL, '2024-07-27', NULL, '点对点', '深圳湾春茧体育馆', '深圳深铁皇冠假日酒店', NULL, NULL, '22:00:00', '00:00:00', NULL, 4, '', '出发时间待定', '{"van":{"type":"van","quantity":1}}', 'pending', 22, '2025-08-22 08:57:11', '2025-08-22 08:59:55', NULL, NULL, NULL, NULL, NULL, '0.00', NULL),
(97, 4, 79, NULL, '2024-07-28', NULL, '混合交通安排（自定义）', '深圳深铁皇冠假日酒店', '珠海横琴口岸', NULL, NULL, '10:30:00', '00:00:00', NULL, 2, '', '具体乘客未知', '{"van":{"type":"van","quantity":1}}', 'confirmed', 22, '2025-08-22 09:01:42', '2025-08-26 09:24:56', NULL, NULL, NULL, NULL, NULL, '0.00', NULL),
(99, 4, 84, NULL, '2024-07-27', NULL, '混合交通安排（自定义）', '深圳湾春茧体育馆', '香港', NULL, NULL, '23:00:00', '00:00:00', NULL, 35, '', '', NULL, 'confirmed', 22, '2025-08-22 09:56:06', '2025-08-26 09:24:50', NULL, NULL, NULL, NULL, NULL, '0.00', NULL),
(103, 4, 24, NULL, '2025-08-28', NULL, '接机/站', '深圳宝安机场T2（机场）', '深圳湾春茧体育馆', NULL, NULL, '11:11:00', NULL, NULL, 1, NULL, NULL, '{"van":{"type":"van","quantity":1}}', 'confirmed', 18, '2025-08-28 08:07:49', '2025-08-28 13:43:16', NULL, NULL, NULL, NULL, NULL, '0.00', NULL),
(105, 4, 68, NULL, '2024-07-26', NULL, '点对点', '深圳深铁皇冠假日酒店', '深圳湾春茧体育馆', NULL, NULL, '09:30:00', NULL, NULL, 3, NULL, NULL, '{"van":{"type":"van","quantity":1}}', 'pending', 22, '2025-08-28 08:19:09', '2025-08-28 08:19:09', NULL, NULL, NULL, NULL, NULL, '0.00', NULL);

-- --------------------------------------------------------

--
-- 表的结构 `transportation_statistics_view`
--

CREATE TABLE IF NOT EXISTS `transportation_statistics_view` (
  `vehicle_id` int(11) DEFAULT NULL,
  `fleet_number` varchar(50) DEFAULT NULL,
  `vehicle_type` enum('car','van','minibus','bus','truck','other') DEFAULT NULL,
  `vehicle_model` varchar(100) DEFAULT NULL,
  `seats` int(11) DEFAULT NULL,
  `driver_name` varchar(100) DEFAULT NULL,
  `driver_phone` varchar(20) DEFAULT NULL,
  `project_name` varchar(255) DEFAULT NULL,
  `project_code` varchar(50) DEFAULT NULL,
  `transportation_id` int(11) DEFAULT NULL,
  `travel_date` date DEFAULT NULL,
  `travel_type` enum('接机/站','送机/站','混合交通安排（自定义）','点对点','接站','送站','混合交通安排') DEFAULT NULL,
  `departure_location` varchar(255) DEFAULT NULL,
  `destination_location` varchar(255) DEFAULT NULL,
  `cost` decimal(10,2) DEFAULT NULL,
  `description` text,
  `status` enum('pending','confirmed','cancelled') DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `hotel_reports`
--
ALTER TABLE `hotel_reports`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `site_config`
--
ALTER TABLE `site_config`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_config_key` (`config_key`);

--
-- Indexes for table `transportation_fleet_assignments`
--
ALTER TABLE `transportation_fleet_assignments`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `transportation_passengers`
--
ALTER TABLE `transportation_passengers`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `transportation_reports`
--
ALTER TABLE `transportation_reports`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT,AUTO_INCREMENT=68;
--
-- AUTO_INCREMENT for table `hotel_reports`
--
ALTER TABLE `hotel_reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT,AUTO_INCREMENT=42;
--
-- AUTO_INCREMENT for table `site_config`
--
ALTER TABLE `site_config`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT,AUTO_INCREMENT=617;
--
-- AUTO_INCREMENT for table `transportation_fleet_assignments`
--
ALTER TABLE `transportation_fleet_assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT,AUTO_INCREMENT=85;
--
-- AUTO_INCREMENT for table `transportation_passengers`
--
ALTER TABLE `transportation_passengers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT,AUTO_INCREMENT=292;
--
-- AUTO_INCREMENT for table `transportation_reports`
--
ALTER TABLE `transportation_reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT,AUTO_INCREMENT=106;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
