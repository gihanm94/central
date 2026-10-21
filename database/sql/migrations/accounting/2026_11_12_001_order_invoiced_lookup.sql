-- Accounting → Orders "Invoiced" (yes / no): invoice lines are found by the order's id or by its order number.
CREATE INDEX IF NOT EXISTS erp_invoices_customer_order_number_idx ON erp_invoices (customer_order_number);
CREATE INDEX IF NOT EXISTS erp_orders_number_prefix_idx ON erp_orders (upper(substring(order_number FROM '^[A-Za-z]+')));
