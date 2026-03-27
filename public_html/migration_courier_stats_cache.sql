-- Customer Courier Stats Cache Table
-- Stores per-phone delivery success rate data
-- TTL: 12 hours (data re-fetched from courier APIs on expiry or manual recheck)
CREATE TABLE IF NOT EXISTS `customer_courier_stats` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `phone_hash` varchar(32) NOT NULL COMMENT 'MD5 of normalized phone (last 11 digits)',
  `phone_display` varchar(20) NOT NULL COMMENT 'Original phone for display',
  `total_orders` int(11) NOT NULL DEFAULT 0,
  `delivered` int(11) NOT NULL DEFAULT 0,
  `cancelled` int(11) NOT NULL DEFAULT 0,
  `returned` int(11) NOT NULL DEFAULT 0,
  `success_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `total_spent` decimal(12,2) NOT NULL DEFAULT 0.00,
  `courier_breakdown` text DEFAULT NULL COMMENT 'JSON: [{name,delivered,total}]',
  `fetched_at` datetime NOT NULL COMMENT 'When data was last fetched from courier APIs',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_phone_hash` (`phone_hash`),
  KEY `idx_fetched` (`fetched_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
