-- Migration: Clean up orphaned TEMP orders from incomplete order recovery
-- Date: 2026-04-01
-- These TEMP orders were created by open_incomplete but never confirmed.
-- They pollute the orders list (especially cancelled ones).

-- Delete order items for orphaned TEMP orders that are cancelled/incomplete
DELETE oi FROM order_items oi
INNER JOIN orders o ON oi.order_id = o.id
WHERE o.order_number LIKE 'TEMP-%'
AND o.order_status IN ('cancelled', 'incomplete');

-- Delete status history for orphaned TEMP orders  
DELETE osh FROM order_status_history osh
INNER JOIN orders o ON osh.order_id = o.id
WHERE o.order_number LIKE 'TEMP-%'
AND o.order_status IN ('cancelled', 'incomplete');

-- Delete tags for orphaned TEMP orders
DELETE ot FROM order_tags ot
INNER JOIN orders o ON ot.order_id = o.id
WHERE o.order_number LIKE 'TEMP-%'
AND o.order_status IN ('cancelled', 'incomplete');

-- Delete the orphaned TEMP orders themselves
DELETE FROM orders
WHERE order_number LIKE 'TEMP-%'
AND order_status IN ('cancelled', 'incomplete');

-- Reset recovered_order_id for incomplete_orders that pointed to now-deleted TEMP orders
UPDATE incomplete_orders 
SET recovered_order_id = NULL
WHERE recovered_order_id IS NOT NULL
AND recovered_order_id NOT IN (SELECT id FROM orders);

-- Reset is_recovered for incomplete_orders where the recovered order no longer exists
-- (handles both column name variants)
UPDATE incomplete_orders 
SET is_recovered = 0 
WHERE recovered_order_id IS NULL 
AND is_recovered = 1;
