-- Migration: Add hide_header column to products table
-- Date: 2026-04-04
-- Feature: Single Product Visibility — allows per-product header hide

ALTER TABLE `products` ADD COLUMN IF NOT EXISTS `hide_header` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_active`;
