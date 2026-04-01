-- Migration: Product Variant Combinations System
-- Date: 2026-04-01
-- Purpose: WooCommerce-style attribute + combination system for variable products

-- New table: product_attributes (stores attribute definitions per product: Frame Color, Lens Color, Size etc.)
CREATE TABLE IF NOT EXISTS `product_attributes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `attribute_name` varchar(100) NOT NULL,
  `attribute_values` text NOT NULL COMMENT 'JSON array of possible values',
  `is_visible` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Show on product page',
  `is_variation` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Used for generating combinations',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_product_attrs` (`product_id`),
  CONSTRAINT `fk_product_attrs_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- New table: product_variant_combinations (one row per combination e.g. "Golden + Black + Large")
CREATE TABLE IF NOT EXISTS `product_variant_combinations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `combination_key` varchar(500) NOT NULL COMMENT 'Sorted JSON e.g. {"Frame Color":"Golden","Lens Color":"Black","Size":"Large"}',
  `combination_label` varchar(500) NOT NULL COMMENT 'Display label e.g. Golden / Black / Large',
  `sku` varchar(100) DEFAULT NULL,
  `regular_price` decimal(12,2) DEFAULT NULL,
  `sale_price` decimal(12,2) DEFAULT NULL,
  `cost_price` decimal(12,2) DEFAULT NULL,
  `stock_quantity` int(11) NOT NULL DEFAULT 0,
  `manage_stock` tinyint(1) NOT NULL DEFAULT 1,
  `weight` decimal(10,4) DEFAULT NULL,
  `variant_image` varchar(500) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_combo_product` (`product_id`),
  KEY `idx_combo_key` (`product_id`, `combination_key`(255)),
  CONSTRAINT `fk_combo_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- Add column to products table to indicate which variation system is in use
ALTER TABLE `products` ADD COLUMN `variation_mode` ENUM('legacy','combination') NOT NULL DEFAULT 'legacy' AFTER `product_type`;
