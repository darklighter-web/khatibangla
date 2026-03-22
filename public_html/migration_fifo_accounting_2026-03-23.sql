-- =============================================
-- FIFO Inventory Batch System Migration
-- Date: 2026-03-23
-- Run this BEFORE deploying the new inventory.php
-- =============================================

-- Stock batches: each "stock in" creates a batch with cost + remaining qty
CREATE TABLE IF NOT EXISTS stock_batches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    warehouse_id INT NOT NULL,
    product_id INT NOT NULL,
    variant_id INT DEFAULT NULL,
    batch_ref VARCHAR(80) DEFAULT NULL COMMENT 'PO number, supplier ref, etc.',
    quantity_received INT NOT NULL DEFAULT 0,
    quantity_remaining INT NOT NULL DEFAULT 0,
    cost_price DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Per-unit cost at time of purchase',
    supplier_id INT DEFAULT NULL,
    received_date DATE NOT NULL,
    expiry_date DATE DEFAULT NULL,
    note TEXT DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_product (product_id),
    INDEX idx_warehouse_product (warehouse_id, product_id),
    INDEX idx_remaining (quantity_remaining),
    INDEX idx_received (received_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stock consumption log: tracks which batches were consumed (FIFO)
CREATE TABLE IF NOT EXISTS stock_consumption (
    id INT AUTO_INCREMENT PRIMARY KEY,
    batch_id INT NOT NULL,
    product_id INT NOT NULL,
    order_id INT DEFAULT NULL,
    quantity_consumed INT NOT NULL,
    cost_per_unit DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    consumed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_batch (batch_id),
    INDEX idx_order (order_id),
    INDEX idx_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed existing stock into batch 0 (legacy stock before FIFO)
INSERT IGNORE INTO stock_batches (warehouse_id, product_id, quantity_received, quantity_remaining, cost_price, batch_ref, received_date, note)
SELECT 
    COALESCE(ws.warehouse_id, 1),
    p.id,
    p.stock_quantity,
    p.stock_quantity,
    COALESCE(p.cost_price, 0),
    'LEGACY',
    CURDATE(),
    'Initial stock migrated to FIFO system'
FROM products p
LEFT JOIN warehouse_stock ws ON ws.product_id = p.id
WHERE p.stock_quantity > 0
GROUP BY p.id;
