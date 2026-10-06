<?php
/*
 | ERP (Monitor) and e-Tax portal connection defaults, and what the sync copies.
 | Everything in "connection", "endpoints" and "inet" can be changed on Accounting → ERP connection (saved in the
 | accounting database); passwords and keys are never kept in this file.
 */
return [
    'connection' => ['ip' => '', 'port' => '8001', 'username' => '', 'verify_tls' => '0', 'timeout' => '120'],

    'schedule' => [
        'enabled' => '0', 'tz' => 'Asia/Bangkok', 'hour_start' => '7', 'hour_end' => '20', 'days' => '1,2,3,4,5',
        'hot_minutes' => '3', 'cold_minutes' => '30',
    ],

    /** key => [path, label] — the ERP API addresses (added to https://ip:port) */
    'endpoints' => [
        'tokenUrl'            => ['/EN/001.1/login', 'Login (token)'],
        'warehousesUrl'       => ['/sv/001.1/api/v1/Common/Warehouses', 'Warehouses'],
        'personUrl'           => ['/sv/001.1/api/v1/Common/Persons', 'Persons / sellers'],
        'vatRateUrl'          => ['/sv/001.1/api/v1/Common/VatRates', 'VAT rates'],
        'vatGroupUrl'         => ['/sv/001.1/api/v1/Common/VatGroups', 'VAT groups'],
        'countryUrl'          => ['/sv/001.1/api/v1/Common/Countries', 'Countries'],
        'departmentUrl'       => ['/sv/001.1/api/v1/Common/Departments', 'Departments'],
        'paymentTermUrl'      => ['/sv/001.1/api/v1/Common/PaymentTerms', 'Payment terms'],
        'partUrl'             => ['/sv/001.1/api/v1/Inventory/Parts', 'Parts / products'],
        'contactRefUrl'       => ['/sv/001.1/api/v1/Common/BusinessContactReferences', 'Business contact references'],
        'addressUrl'          => ['/sv/001.1/api/v1/Common/Addresses', 'Addresses'],
        'customerUrl'         => ['/sv/001.1/api/v1/Sales/Customers', 'Customers'],
        'invoiceLogUrl'       => ['/sv/001.1/api/v1/Sales/InvoiceLogs', 'Invoice log'],
        'commentUrl'          => ['/sv/001.1/api/v1/Common/Comments', 'Comments'],
        'orderInvoicesUrl'    => ['/sv/001.1/api/v1/Sales/CustomerOrderInvoices', 'Customer order invoices'],
        'orderDeliveryRowUrl' => ['/sv/001.1/api/v1/Sales/CustomerOrderDeliveryRows', 'Order delivery rows'],
        'customerOrderUrl'    => ['/EN/001.1/api/v1/Sales/CustomerOrders', 'Customer orders'],
        'customerOrderRowUrl' => ['/sv/001.1/api/v1/Sales/CustomerOrderRows', 'Customer order rows'],
        'extraFieldUrl'       => ['/sv/001.1/api/v1/Common/ExtraFields', 'Extra fields'],
        'deliveryMethodUrl'   => ['/sv/001.1/api/v1/Common/DeliveryMethods', 'Delivery methods'],
        'productRecordUrl'    => ['/sv/001.1/api/v1/Inventory/ProductRecords', 'Product records'],
        'orderPaymentRowUrl'  => ['/sv/001.1/api/v1/Sales/CustomerOrderPaymentPlanRows', 'Order payment plan rows'],
        'deliveryAddressUrl'  => ['/sv/001.1/api/v1/Common/DeliveryAddresses', 'Delivery addresses'],
        'accountYearUrl'      => ['/sv/001.1/api/v1/Accounting/AccountingYears', 'Accounting years'],
        'balanceUrl'          => ['/sv/001.1/api/v1/Accounting/Balances', 'Balances'],
        'balanceRowUrl'       => ['/sv/001.1/api/v1/Accounting/BalanceRowDays', 'Balance rows per day'],
        'quantityChangeUrl'   => ['/sv/001.1/api/v1/Inventory/QuantityChanges', 'Quantity changes'],
        'openingBalanceUrl'   => ['/sv/001.1/api/v1/Accounting/OpeningBalances', 'Opening balances'],
        'stockBalanceUrl'     => ['/sv/001.1/api/v1/Inventory/StockBalanceChanges', 'Stock balance changes'],
        'receivableUrl'       => ['/sv/001.1/api/v1/Accounting/AccountsReceivables', 'Accounts receivable'],
        'supplierUrl'         => ['/sv/001.1/api/v1/Purchase/Suppliers', 'Suppliers'],
        'purchaseOrderUrl'    => ['/sv/001.1/api/v1/Purchase/PurchaseOrders', 'Purchase orders'],
        'partCodeUrl'         => ['/sv/001.1/api/v1/Inventory/PartCodes', 'Part codes'],
        'stockTransactionUrl' => ['/sv/001.1/api/v1/Inventory/StockTransactions', 'Stock transactions'],
        'voucherUrl'          => ['/sv/001.1/api/v1/Accounting/Vouchers', 'Vouchers'],
        'voucherRowUrl'       => ['/sv/001.1/api/v1/Accounting/VoucherRows', 'Voucher rows'],
        'fixedAssetsUrl'      => ['/sv/001.1/api/v1/Accounting/FixedAssets', 'Fixed assets'],
        'payableUrl'          => ['/sv/001.1/api/v1/Accounting/AccountsPayables', 'Accounts payable'],
        'quotesUrl'           => ['/sv/001.1/api/v1/Sales/Quotes', 'Quotes'],
    ],

    /** e-Tax (INET) portal — used by the e-tax invoice step that comes later */
    'inet' => [
        'sendApi'   => 'https://service-etax.one.th/service/etaxsigndocumentjson',
        'statusApi' => 'https://service-etax.one.th/getdocument/getdocumentstatus',
        'paramsApi' => 'https://service-etax.one.th/getdocument/getdocumentparams',
    ],

    /**
     * What is copied. group: hot = the latest N rows every few minutes, cold = reference data every half hour,
     * full = only when someone asks. "entity" is a key of erp_schema.php, "api" a key above,
     * "columns" ($select; skipped when "expand" is used), "children" = rows found inside the answer that are saved too.
     */
    'entities' => [
        'invoice'          => ['label' => 'Invoice log',          'entity' => 'Invoice',         'api' => 'invoiceLogUrl',       'group' => 'hot',  'size' => 10000],
        'delivery_address' => ['label' => 'Delivery addresses',   'entity' => 'DeliveryAddress', 'api' => 'deliveryAddressUrl',  'group' => 'hot',  'size' => 6000],
        'address'          => ['label' => 'Addresses',            'entity' => 'Address',         'api' => 'addressUrl',          'group' => 'hot',  'size' => 10000],
        'comment'          => ['label' => 'Comments',             'entity' => 'Comment',         'api' => 'commentUrl',          'group' => 'hot',  'size' => 10000, 'columns' => ['Id', 'RawText']],
        'order'            => ['label' => 'Customer orders',      'entity' => 'Order',           'api' => 'customerOrderUrl',    'group' => 'hot',  'size' => 10000,
            'expand' => ['PaymentTerm', 'DeliveryAddress', 'MailingAddress', 'InvoiceAddress', 'ExternalComment', 'Rows'],
            'children' => [
                ['path' => 'PaymentTerm', 'entity' => 'PaymentTerm'], ['path' => 'DeliveryAddress', 'entity' => 'Address'], ['path' => 'MailingAddress', 'entity' => 'Address'],
                ['path' => 'InvoiceAddress', 'entity' => 'Address'], ['path' => 'ExternalComment', 'entity' => 'Comment'], ['path' => 'Rows', 'entity' => 'OrderRow', 'many' => true, 'stamp' => ['parent_order_id' => 'id']],
            ]],
        'order_row'        => ['label' => 'Order rows',           'entity' => 'OrderRow',        'api' => 'customerOrderRowUrl', 'group' => 'hot',  'size' => 20000,
            'expand' => ['Part', 'Unit'], 'children' => [['path' => 'Part', 'entity' => 'Product'], ['path' => 'Unit', 'entity' => 'Unit']]],
        'order_invoice'    => ['label' => 'Order invoices',       'entity' => 'OrderInvoice',    'api' => 'orderInvoicesUrl',    'group' => 'hot',  'size' => 3000,
            'expand' => ['DeliveryRows', 'PaymentTerm'], 'children' => [['path' => 'DeliveryRows', 'entity' => 'OrderDelivery', 'many' => true], ['path' => 'PaymentTerm', 'entity' => 'PaymentTerm']]],
        'order_delivery'   => ['label' => 'Order delivery rows',  'entity' => 'OrderDelivery',   'api' => 'orderDeliveryRowUrl', 'group' => 'hot',  'size' => 10000,
            'expand' => ['InvoiceRow'], 'children' => [['path' => 'InvoiceRow', 'entity' => 'Invoice']]],
        'order_payment'    => ['label' => 'Order payment rows',   'entity' => 'OrderPayment',    'api' => 'orderPaymentRowUrl',  'group' => 'hot',  'size' => 10000],
        'customer'         => ['label' => 'Customers',            'entity' => 'Customer',        'api' => 'customerUrl',         'group' => 'hot',  'size' => 6000,
            'expand' => ['MailingAddress', 'DeliveryAddresses', 'InvoiceAddress', 'PaymentTerm', 'Seller', 'VatRate', 'CommunicationAddresses', 'ExtraFields', 'References', 'Comment'],
            'children' => [
                ['path' => 'MailingAddress', 'entity' => 'Address'], ['path' => 'InvoiceAddress', 'entity' => 'Address'], ['path' => 'Seller', 'entity' => 'Seller'],
                ['path' => 'VatRate', 'entity' => 'Vat'], ['path' => 'PaymentTerm', 'entity' => 'PaymentTerm'], ['path' => 'Comment', 'entity' => 'Comment'],
                ['path' => 'DeliveryAddresses', 'entity' => 'DeliveryAddress', 'many' => true], ['path' => 'ExtraFields', 'entity' => 'ExtraField', 'many' => true],
                ['path' => 'CommunicationAddresses', 'entity' => 'DeliveryContact', 'many' => true, 'stamp' => ['customer_id' => 'id']],
            ]],
        'stock_transaction' => ['label' => 'Stock transactions',  'entity' => 'StockTransaction', 'api' => 'stockTransactionUrl', 'group' => 'hot', 'size' => 10000],

        'seller'           => ['label' => 'Sellers (persons)',    'entity' => 'Seller',          'api' => 'personUrl',           'group' => 'cold', 'size' => 500,
            'columns' => ['Id', 'CellPhoneNumber', 'EmailAddress', 'EmployeeNumber', 'FirstName', 'LastName', 'Initials', 'DepartmentId', 'IsSeller']],
        'department'       => ['label' => 'Departments',          'entity' => 'Department',      'api' => 'departmentUrl',       'group' => 'cold', 'size' => 100],
        'vat'              => ['label' => 'VAT rates',            'entity' => 'Vat',             'api' => 'vatRateUrl',          'group' => 'cold', 'size' => 200, 'columns' => ['Id', 'Percentage']],
        'vat_group'        => ['label' => 'VAT groups',           'entity' => 'VatGroup',        'api' => 'vatGroupUrl',         'group' => 'cold', 'size' => 200],
        'country'          => ['label' => 'Countries',            'entity' => 'Country',         'api' => 'countryUrl',          'group' => 'cold', 'size' => 300],
        'warehouse'        => ['label' => 'Warehouses',           'entity' => 'Warehouse',       'api' => 'warehousesUrl',       'group' => 'cold', 'size' => 200, 'columns' => ['Id', 'Code', 'Name']],
        'delivery_method'  => ['label' => 'Delivery methods',     'entity' => 'DeliveryMethod',  'api' => 'deliveryMethodUrl',   'group' => 'cold', 'size' => 200,
            'columns' => ['Id', 'IsDefault', 'Number', 'Description', 'ShippingServiceId', 'ShippingTemplateId', 'SupplierId', 'Code']],
        'product'          => ['label' => 'Products (parts)',     'entity' => 'Product',         'api' => 'partUrl',             'group' => 'cold', 'size' => 20000,
            'columns' => ['Id', 'PartNumber', 'Description', 'StandardPrice', 'Alias', 'PartTemplateId', 'PartCodeId']],
        'payment_term'     => ['label' => 'Payment terms',        'entity' => 'PaymentTerm',     'api' => 'paymentTermUrl',      'group' => 'cold', 'size' => 500],
        'business_contact' => ['label' => 'Business contacts',    'entity' => 'BusinessContact', 'api' => 'contactRefUrl',       'group' => 'cold', 'size' => 5000],
        'extra_field'      => ['label' => 'Extra fields',         'entity' => 'ExtraField',      'api' => 'extraFieldUrl',       'group' => 'cold', 'size' => 10000,
            'columns' => ['Id', 'ParentId', 'Identifier', 'Type', 'StringValue', 'CommentId'], 'expand' => ['Comment']],

        'receivable'       => ['label' => 'Accounts receivable',  'entity' => 'Receivable',      'api' => 'receivableUrl',       'group' => 'full', 'size' => 10000],
        'supplier'         => ['label' => 'Suppliers',            'entity' => 'Supplier',        'api' => 'supplierUrl',         'group' => 'full', 'size' => 5000],
        'payable'          => ['label' => 'Accounts payable',     'entity' => 'Payable',         'api' => 'payableUrl',          'group' => 'full', 'size' => 10000],
        'purchase_order'   => ['label' => 'Purchase orders',      'entity' => 'PurchaseOrder',   'api' => 'purchaseOrderUrl',    'group' => 'full', 'size' => 10000],
        'voucher'          => ['label' => 'Vouchers',             'entity' => 'Voucher',         'api' => 'voucherUrl',          'group' => 'full', 'size' => 10000],
        'voucher_row'      => ['label' => 'Voucher rows',         'entity' => 'VoucherRow',      'api' => 'voucherRowUrl',       'group' => 'full', 'size' => 20000],
        'quote'            => ['label' => 'Quotes',               'entity' => 'Quotes',          'api' => 'quotesUrl',           'group' => 'full', 'size' => 5000],
        'product_record'   => ['label' => 'Product records',      'entity' => 'ProductRecord',   'api' => 'productRecordUrl',    'group' => 'full', 'size' => 10000,
            'expand' => ['ExtraFields', 'Comment'], 'children' => [['path' => 'ExtraFields', 'entity' => 'ExtraField', 'many' => true], ['path' => 'Comment', 'entity' => 'Comment']]],
    ],
];
