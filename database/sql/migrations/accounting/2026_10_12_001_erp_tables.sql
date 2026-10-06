-- ERP mirror tables (data pulled from the ERP API by bin/erp-sync.php). Generated from the former Java entities.

CREATE TABLE IF NOT EXISTS erp_addresses (
    id BIGINT PRIMARY KEY,
    addressee TEXT,
    field1 TEXT,
    field2 TEXT,
    field3 TEXT,
    field4 TEXT,
    field5 TEXT,
    locality TEXT,
    region TEXT,
    postal_code TEXT,
    country_id BIGINT,
    synced_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS erp_business_contacts (
    id BIGINT PRIMARY KEY,
    category TEXT,
    comment_id BIGINT,
    extra_information1 TEXT,
    extra_information2 TEXT,
    extra_information3 TEXT,
    name TEXT,
    note TEXT,
    cell_phone_number TEXT,
    email_address TEXT,
    fax_number TEXT,
    phone_number TEXT,
    synced_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS erp_comments (
    id BIGINT PRIMARY KEY,
    raw_text TEXT,
    synced_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS erp_countries (
    id BIGINT PRIMARY KEY,
    code TEXT,
    description TEXT,
    is_active BOOLEAN,
    synced_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS erp_customers (
    id BIGINT PRIMARY KEY,
    name TEXT,
    alternative_name TEXT,
    is_private_customer BOOLEAN,
    mailing_address_id BIGINT,
    corporation_identification_number TEXT,
    vat_number TEXT,
    code TEXT,
    reseller_id BIGINT,
    seller_id BIGINT,
    comment_id BIGINT,
    default_reference_id BIGINT,
    credit_limit NUMERIC(24,8),
    personal_number TEXT,
    active_delivery_address_id BIGINT,
    date_for_transition_to_actual_contact TIMESTAMPTZ,
    synced_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS erp_delivery_addresses (
    id BIGINT PRIMARY KEY,
    addressee TEXT,
    field1 TEXT,
    field2 TEXT,
    field3 TEXT,
    field4 TEXT,
    field5 TEXT,
    locality TEXT,
    region TEXT,
    postal_code TEXT,
    country_id BIGINT,
    synced_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS erp_delivery_contacts (
    id BIGINT PRIMARY KEY,
    communication_address_value TEXT,
    remarks TEXT,
    recipient_of INTEGER,
    type INTEGER,
    type_name TEXT,
    contact_name TEXT,
    customer_id BIGINT,
    synced_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS erp_delivery_methods (
    id BIGINT PRIMARY KEY,
    is_default BOOLEAN,
    number TEXT,
    description TEXT,
    shipping_service_id BIGINT,
    shipping_template_id BIGINT,
    supplier_id BIGINT,
    code TEXT,
    synced_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS erp_departments (
    id BIGINT PRIMARY KEY,
    code TEXT,
    description TEXT,
    name TEXT,
    department_id BIGINT,
    synced_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS erp_extra_fields (
    id BIGINT PRIMARY KEY,
    parent_id BIGINT,
    type INTEGER,
    identifier TEXT,
    string_value TEXT,
    comment_id BIGINT,
    synced_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS erp_invoices (
    id BIGINT PRIMARY KEY,
    invoice_id BIGINT,
    invoice_number BIGINT,
    invoice_date TIMESTAMPTZ,
    customer_id BIGINT,
    delivery_row_id BIGINT,
    customer_code TEXT,
    customer_group BIGINT,
    customer_order_id BIGINT,
    customer_order_number TEXT,
    order_type TEXT,
    ordered_quantity NUMERIC(24,8),
    invoiced_quantity NUMERIC(24,8),
    price NUMERIC(24,8),
    discount NUMERIC(24,8),
    part_id BIGINT,
    part_number TEXT,
    unit_code TEXT,
    product_group_number TEXT,
    currency_code TEXT,
    exchange_rate NUMERIC(24,8),
    seller TEXT,
    account_number TEXT,
    user_id BIGINT,
    user_name TEXT,
    conversion_factor NUMERIC(24,8),
    order_row_type INTEGER,
    synced_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS erp_orders (
    id BIGINT PRIMARY KEY,
    business_contact_order_number TEXT,
    delivery_method_id BIGINT,
    delivery_term_id BIGINT,
    delivery_address_id BIGINT,
    mailing_address_id BIGINT,
    payment_term_id BIGINT,
    order_date TIMESTAMPTZ,
    order_number TEXT,
    customer_id BIGINT,
    vat_number TEXT,
    our_reference_id BIGINT,
    seller_id BIGINT,
    vat_group_id BIGINT,
    business_contact_reference_id BIGINT,
    consignee_reference_id BIGINT,
    external_comment_id BIGINT,
    internal_comment_id BIGINT,
    order_type_id BIGINT,
    is_credit BOOLEAN,
    invoice_type INTEGER,
    source_of_alternative_delivery_addresses INTEGER,
    shipment_payer INTEGER,
    comprehensive_invoice_grouping_mode INTEGER,
    status INTEGER,
    unpaid_advance_warning_type INTEGER,
    transfer_status INTEGER,
    account_group_id BIGINT,
    purchase_order_id BIGINT,
    is_stock_order BOOLEAN,
    life_cycle_state INTEGER,
    priority INTEGER,
    send_method INTEGER,
    partial_delivery_type INTEGER,
    warehouse_id BIGINT,
    synced_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS erp_order_deliveries (
    id BIGINT PRIMARY KEY,
    customer_order_id BIGINT,
    customer_order_row_id BIGINT,
    delivery_date TIMESTAMPTZ,
    free_text TEXT,
    invoice_id BIGINT,
    parent_invoice_id BIGINT,
    pick_list_delivery_date TIMESTAMPTZ,
    quantity_change_id BIGINT,
    invoice_row_id BIGINT,
    synced_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS erp_order_invoices (
    id BIGINT PRIMARY KEY,
    delivery_note_number BIGINT,
    order_delivery_number TEXT,
    delivery_note_creation_date TIMESTAMPTZ,
    business_contact_order_id BIGINT,
    active_delivery_address_customer_id BIGINT,
    active_delivery_address_supplier_id BIGINT,
    business_contact_reference_id BIGINT,
    account_group_id BIGINT,
    our_reference_id BIGINT,
    seller_id BIGINT,
    proforma_number BIGINT,
    proforma_date TIMESTAMPTZ,
    our_reference_name TEXT,
    partial_invoice_type INTEGER,
    ledger_id BIGINT,
    status BIGINT,
    synced_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS erp_order_payments (
    id BIGINT PRIMARY KEY,
    customer_order_id BIGINT,
    invoicing_period TEXT,
    vat_rate_id BIGINT,
    amount NUMERIC(24,8),
    amount_currency_id BIGINT,
    is_reset BOOLEAN,
    coding_entry_id BIGINT,
    partial_invoice_type INTEGER,
    part_id BIGINT,
    override_description TEXT,
    fraction_of_total NUMERIC(24,8),
    payment_term_id BIGINT,
    row_index INTEGER,
    synced_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS erp_order_rows (
    id BIGINT PRIMARY KEY,
    order_row_type INTEGER,
    part_id BIGINT,
    payment_plan_row_id BIGINT,
    additional_row_description TEXT,
    free_text TEXT,
    parent_row_id BIGINT,
    delivery_date TIMESTAMPTZ,
    ordered_quantity NUMERIC(24,8),
    price NUMERIC(24,8),
    discount NUMERIC(24,8),
    conversion_factor NUMERIC(24,8),
    unit_id BIGINT,
    vat_rate_id BIGINT,
    order_date TIMESTAMPTZ,
    parent_order_id BIGINT,
    desired_delivery_date TIMESTAMPTZ,
    creation_context INTEGER,
    row_index INTEGER,
    synced_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS erp_payables (
    id BIGINT PRIMARY KEY,
    payment_method_id BIGINT,
    suppliers_invoice_number TEXT,
    invoice_type INTEGER,
    is_credit BOOLEAN,
    purchase_order_id BIGINT,
    preliminary_booking_date TIMESTAMPTZ,
    voucher_date TIMESTAMPTZ,
    cancel_voucher_date TIMESTAMPTZ,
    supplier_account_group_id BIGINT,
    country_id BIGINT,
    invoice_number TEXT,
    invoice_date TIMESTAMPTZ,
    due_date TIMESTAMPTZ,
    invoice_amount NUMERIC(24,8),
    invoice_amount_currency_id BIGINT,
    invoice_amount_in_company_currency NUMERIC(24,8),
    invoice_amount_in_company_currency_currency_id BIGINT,
    vat_amount NUMERIC(24,8),
    vat_amount_currency_id BIGINT,
    vat_amount_in_company_currency NUMERIC(24,8),
    vat_amount_in_company_currency_currency_id BIGINT,
    rest_amount NUMERIC(24,8),
    rest_amount_currency_id BIGINT,
    rest_amount_in_company_currency NUMERIC(24,8),
    ordered_rest_amount NUMERIC(24,8),
    currency_id BIGINT,
    exchange_rate NUMERIC(24,8),
    paid_in_full_date TIMESTAMPTZ,
    comment_id BIGINT,
    reference_number TEXT,
    vat_group_id BIGINT,
    payment_term_id BIGINT,
    business_contact_id BIGINT,
    synced_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS erp_payment_terms (
    id BIGINT PRIMARY KEY,
    number TEXT,
    description TEXT,
    grace_period_in_days INTEGER,
    add_days_to_eom INTEGER,
    is_free_delivery_month BOOLEAN,
    method INTEGER,
    is_default BOOLEAN,
    code TEXT,
    synced_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS erp_products (
    id BIGINT PRIMARY KEY,
    part_number TEXT,
    description TEXT,
    alias TEXT,
    standard_price NUMERIC(24,8),
    part_template_id BIGINT,
    part_code_id TEXT,
    synced_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS erp_product_records (
    id BIGINT PRIMARY KEY,
    product_record_type INTEGER,
    comment_id BIGINT,
    serial_number TEXT,
    part_id BIGINT,
    customer_order_id BIGINT,
    charge_number TEXT,
    registration_no TEXT,
    serial_number_with_part_number TEXT,
    actual_arrival_date TIMESTAMPTZ,
    synced_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS erp_purchase_orders (
    id BIGINT PRIMARY KEY,
    order_date TIMESTAMPTZ,
    order_number TEXT,
    goods_label TEXT,
    our_reference_id TEXT,
    our_reference_name TEXT,
    from_inquiry_number TEXT,
    vat_group_id TEXT,
    business_contact_id TEXT,
    account_group_id TEXT,
    synced_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS erp_quotes (
    id BIGINT PRIMARY KEY,
    payment_term_id BIGINT,
    payment_term_description TEXT,
    grace_period_in_days INTEGER,
    delivery_method_id BIGINT,
    delivery_term_id BIGINT,
    delivery_term_description TEXT,
    delivery_method_description TEXT,
    shipment_payer INTEGER,
    business_contact_order_number TEXT,
    our_reference_id BIGINT,
    our_reference_name TEXT,
    business_contact_reference_id BIGINT,
    business_contact_reference_name TEXT,
    goods_label TEXT,
    currency_id BIGINT,
    vat_group_id BIGINT,
    warehouse_id BIGINT,
    priority INTEGER,
    project_id BIGINT,
    send_method INTEGER,
    invoice_printout_method INTEGER,
    internal_comment_id BIGINT,
    external_comment_id BIGINT,
    transport_time INTEGER,
    life_cycle_state INTEGER,
    version INTEGER,
    use_forward_rate BOOLEAN,
    exchange_rate NUMERIC(24,8),
    customer_id BIGINT,
    order_number TEXT,
    order_date TIMESTAMPTZ,
    seller_id BIGINT,
    delivery_address_id BIGINT,
    mailing_address_id BIGINT,
    vat_number TEXT,
    status INTEGER,
    quote_date TIMESTAMPTZ,
    quote_description TEXT,
    latest_contact_date TIMESTAMPTZ,
    request_for_quote_date TIMESTAMPTZ,
    validity_time_id BIGINT,
    validity_time_description TEXT,
    validity_time_in_days INTEGER,
    valid_through_date TIMESTAMPTZ,
    probability_id BIGINT,
    reason_code_lost_quote_id BIGINT,
    reason_lost_comment_id BIGINT,
    order_type_id BIGINT,
    preliminary BOOLEAN,
    currency_exchange_type_id BIGINT,
    delivery_time_id BIGINT,
    delivery_time_in_days INTEGER,
    account_group_id BIGINT,
    category_string TEXT,
    destination_for_delivery_term TEXT,
    edited_order_amount_excluding_vat NUMERIC(24,8),
    edited_order_amount_excluding_vat_currency_id BIGINT,
    edited_order_amount_discount NUMERIC(24,8),
    type_of_order_amount_entered INTEGER,
    factoring BOOLEAN,
    business_opportunity_id BIGINT,
    related_quotes_id BIGINT,
    synced_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS erp_receivables (
    id BIGINT PRIMARY KEY,
    invoice_type INTEGER,
    latest_reminder_date TIMESTAMPTZ,
    payment_reminder BOOLEAN,
    partial_invoice_type INTEGER,
    number_of_payment_reminders INTEGER,
    collection_date TIMESTAMPTZ,
    collection BOOLEAN,
    interest_type INTEGER,
    status INTEGER,
    late_payment_fee NUMERIC(24,8),
    business_contact_id BIGINT,
    cancel_comment_id BIGINT,
    cancel_date TIMESTAMPTZ,
    invoice_number BIGINT,
    invoice_date TIMESTAMPTZ,
    due_date TIMESTAMPTZ,
    is_credit BOOLEAN,
    invoice_amount NUMERIC(24,8),
    invoice_amount_currency_id BIGINT,
    invoice_amount_in_company_currency NUMERIC(24,8),
    vat_amount NUMERIC(24,8),
    vat_amount_currency_id BIGINT,
    rest_amount NUMERIC(24,8),
    rest_amount_currency_id BIGINT,
    ordered_rest_amount NUMERIC(24,8),
    paid_in_full_date TIMESTAMPTZ,
    exchange_rate NUMERIC(24,8),
    synced_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS erp_sellers (
    id BIGINT PRIMARY KEY,
    first_name TEXT,
    last_name TEXT,
    phone_number TEXT,
    email_address TEXT,
    department_id BIGINT,
    employee_number TEXT,
    synced_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS erp_stock_transactions (
    id BIGINT PRIMARY KEY,
    part_number TEXT,
    part_id BIGINT,
    balance_change NUMERIC(24,8),
    balance_on_part_after_change NUMERIC(24,8),
    balance_on_location_after_change NUMERIC(24,8),
    delivery_date TIMESTAMPTZ,
    location_name TEXT,
    business_transaction_id BIGINT,
    part_location_id BIGINT,
    product_record_id BIGINT,
    batch_number TEXT,
    order_number TEXT,
    synced_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS erp_suppliers (
    id BIGINT PRIMARY KEY,
    supplier_code TEXT,
    name TEXT,
    alternative_name TEXT,
    corporation_identification_number TEXT,
    vat_number TEXT,
    date_for_transition_to_actual_contact TIMESTAMPTZ,
    alias TEXT,
    purchase_account_id BIGINT,
    last_change TEXT,
    synced_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS erp_units (
    id BIGINT PRIMARY KEY,
    code TEXT,
    description TEXT,
    number TEXT,
    synced_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS erp_vats (
    id BIGINT PRIMARY KEY,
    number BIGINT,
    description TEXT,
    percentage NUMERIC(24,8),
    synced_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS erp_vat_groups (
    id BIGINT PRIMARY KEY,
    number BIGINT,
    description TEXT,
    default_vat_rate_id BIGINT,
    synced_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS erp_vouchers (
    id BIGINT PRIMARY KEY,
    series_id BIGINT,
    number TEXT,
    text TEXT,
    voucher_date TIMESTAMPTZ,
    connected_voucher_id BIGINT,
    connection_type TEXT,
    is_preliminary BOOLEAN,
    bundle_number BIGINT,
    comment_id BIGINT,
    accounts_payable_id BIGINT,
    accounts_receivable_ledger_id BIGINT,
    accrual_accounting_row_id BIGINT,
    stock_transaction_log_ledger_id BIGINT,
    manufacturing_order_log_ledger_id BIGINT,
    vat_booking_id BIGINT,
    correction_booking_id BIGINT,
    synced_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS erp_voucher_rows (
    id BIGINT PRIMARY KEY,
    entry_identifier TEXT,
    voucher_id BIGINT,
    parent_voucher_date TIMESTAMPTZ,
    exchange_rate NUMERIC(24,8),
    debit_in_company_currency NUMERIC(24,8),
    debit_in_company_currency_currency_id BIGINT,
    credit_in_company_currency NUMERIC(24,8),
    credit_in_company_currency_currency_id BIGINT,
    credit NUMERIC(24,8),
    credit_currency_id BIGINT,
    debit NUMERIC(24,8),
    debit_currency_id BIGINT,
    quantity NUMERIC(24,8),
    balance_id BIGINT,
    synced_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS erp_warehouses (
    id BIGINT PRIMARY KEY,
    code TEXT,
    name TEXT,
    synced_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS erp_orders_customer_id_idx ON erp_orders (customer_id);
CREATE INDEX IF NOT EXISTS erp_orders_order_number_idx ON erp_orders (order_number);
CREATE INDEX IF NOT EXISTS erp_order_rows_parent_order_id_idx ON erp_order_rows (parent_order_id);
CREATE INDEX IF NOT EXISTS erp_invoices_invoice_number_idx ON erp_invoices (invoice_number);
CREATE INDEX IF NOT EXISTS erp_invoices_customer_order_id_idx ON erp_invoices (customer_order_id);
CREATE INDEX IF NOT EXISTS erp_invoices_customer_id_idx ON erp_invoices (customer_id);
CREATE INDEX IF NOT EXISTS erp_customers_code_idx ON erp_customers (code);
CREATE INDEX IF NOT EXISTS erp_order_invoices_delivery_note_number_idx ON erp_order_invoices (delivery_note_number);
CREATE INDEX IF NOT EXISTS erp_order_deliveries_customer_order_id_idx ON erp_order_deliveries (customer_order_id);
CREATE INDEX IF NOT EXISTS erp_order_payments_customer_order_id_idx ON erp_order_payments (customer_order_id);
CREATE INDEX IF NOT EXISTS erp_stock_transactions_order_number_idx ON erp_stock_transactions (order_number);
CREATE INDEX IF NOT EXISTS erp_receivables_invoice_number_idx ON erp_receivables (invoice_number);
CREATE INDEX IF NOT EXISTS erp_vouchers_voucher_date_idx ON erp_vouchers (voucher_date);
CREATE INDEX IF NOT EXISTS erp_voucher_rows_voucher_id_idx ON erp_voucher_rows (voucher_id);
CREATE INDEX IF NOT EXISTS erp_delivery_contacts_customer_id_idx ON erp_delivery_contacts (customer_id);

-- ERP session (login) kept between runs
CREATE TABLE IF NOT EXISTS erp_tokens (
    id                BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    is_primary        BOOLEAN NOT NULL DEFAULT TRUE,
    session_id        TEXT,
    session_suspended BOOLEAN NOT NULL DEFAULT FALSE,
    cookies           TEXT,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at        TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Connection settings edited on the admin page (ERP address, user, API paths, schedule …)
CREATE TABLE IF NOT EXISTS erp_settings (
    key        VARCHAR(80) PRIMARY KEY,
    value      TEXT,
    updated_by BIGINT,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- What the last sync of each entity did
CREATE TABLE IF NOT EXISTS erp_sync_state (
    entity      VARCHAR(60) PRIMARY KEY,
    last_run_at TIMESTAMPTZ,
    last_ok_at  TIMESTAMPTZ,
    status      VARCHAR(12) NOT NULL DEFAULT 'never',     -- never | running | ok | failed
    mode        VARCHAR(12),                              -- all | latest | one
    fetched     INTEGER NOT NULL DEFAULT 0,
    saved       INTEGER NOT NULL DEFAULT 0,
    duration_ms INTEGER,
    error       TEXT
);

-- One row per run (scheduler or "run now")
CREATE TABLE IF NOT EXISTS erp_sync_runs (
    id          BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    kind        VARCHAR(40) NOT NULL,                     -- hot | cold | all | entity:<key>
    source      VARCHAR(12) NOT NULL DEFAULT 'schedule',  -- schedule | manual
    started_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    finished_at TIMESTAMPTZ,
    status      VARCHAR(12) NOT NULL DEFAULT 'running',
    message     TEXT,
    started_by  BIGINT
);
CREATE INDEX IF NOT EXISTS erp_sync_runs_started_idx ON erp_sync_runs (started_at DESC);
