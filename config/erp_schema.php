<?php
// Generated from the former Java entities: ERP JSON field => local column. Do not edit by hand.
return [
    'Address' => [
        'table' => 'erp_addresses',
        'fields' => [
            'id' => [
                'json' => 'Id',
                'type' => 'int',
            ],
            'addressee' => [
                'json' => 'Addressee',
                'type' => 'text',
            ],
            'field1' => [
                'json' => 'Field1',
                'type' => 'text',
            ],
            'field2' => [
                'json' => 'Field2',
                'type' => 'text',
            ],
            'field3' => [
                'json' => 'Field3',
                'type' => 'text',
            ],
            'field4' => [
                'json' => 'Field4',
                'type' => 'text',
            ],
            'field5' => [
                'json' => 'Field5',
                'type' => 'text',
            ],
            'locality' => [
                'json' => 'Locality',
                'type' => 'text',
            ],
            'region' => [
                'json' => 'Region',
                'type' => 'text',
            ],
            'postal_code' => [
                'json' => 'PostalCode',
                'type' => 'text',
            ],
            'country_id' => [
                'json' => 'CountryId',
                'type' => 'int',
            ],
        ],
    ],
    'BusinessContact' => [
        'table' => 'erp_business_contacts',
        'fields' => [
            'id' => [
                'json' => 'Id',
                'type' => 'int',
            ],
            'category' => [
                'json' => 'Category',
                'type' => 'text',
            ],
            'comment_id' => [
                'json' => 'CommentId',
                'type' => 'int',
            ],
            'extra_information1' => [
                'json' => 'ExtraInformation1',
                'type' => 'text',
            ],
            'extra_information2' => [
                'json' => 'ExtraInformation2',
                'type' => 'text',
            ],
            'extra_information3' => [
                'json' => 'ExtraInformation3',
                'type' => 'text',
            ],
            'name' => [
                'json' => 'Name',
                'type' => 'text',
            ],
            'note' => [
                'json' => 'Note',
                'type' => 'text',
            ],
            'cell_phone_number' => [
                'json' => 'CellPhoneNumber',
                'type' => 'text',
            ],
            'email_address' => [
                'json' => 'EmailAddress',
                'type' => 'text',
            ],
            'fax_number' => [
                'json' => 'FaxNumber',
                'type' => 'text',
            ],
            'phone_number' => [
                'json' => 'PhoneNumber',
                'type' => 'text',
            ],
        ],
    ],
    'Comment' => [
        'table' => 'erp_comments',
        'fields' => [
            'id' => [
                'json' => 'Id',
                'type' => 'int',
            ],
            'raw_text' => [
                'json' => 'RawText',
                'type' => 'text',
            ],
        ],
    ],
    'Country' => [
        'table' => 'erp_countries',
        'fields' => [
            'id' => [
                'json' => 'Id',
                'type' => 'int',
            ],
            'code' => [
                'json' => 'Code',
                'type' => 'text',
            ],
            'description' => [
                'json' => 'Description',
                'type' => 'text',
            ],
            'is_active' => [
                'json' => 'IsActive',
                'type' => 'bool',
            ],
        ],
    ],
    'Customer' => [
        'table' => 'erp_customers',
        'fields' => [
            'id' => [
                'json' => 'Id',
                'type' => 'int',
            ],
            'name' => [
                'json' => 'Name',
                'type' => 'text',
            ],
            'alternative_name' => [
                'json' => 'AlternativeName',
                'type' => 'text',
            ],
            'is_private_customer' => [
                'json' => 'IsPrivateCustomer',
                'type' => 'bool',
            ],
            'mailing_address_id' => [
                'json' => 'MailingAddressId',
                'type' => 'int',
            ],
            'corporation_identification_number' => [
                'json' => 'CorporationIdentificationNumber',
                'type' => 'text',
            ],
            'vat_number' => [
                'json' => 'VatNumber',
                'type' => 'text',
            ],
            'code' => [
                'json' => 'Code',
                'type' => 'text',
            ],
            'reseller_id' => [
                'json' => 'ResellerId',
                'type' => 'int',
            ],
            'seller_id' => [
                'json' => 'SellerId',
                'type' => 'int',
            ],
            'comment_id' => [
                'json' => 'CommentId',
                'type' => 'int',
            ],
            'default_reference_id' => [
                'json' => 'DefaultReferenceId',
                'type' => 'int',
            ],
            'credit_limit' => [
                'json' => 'CreditLimit',
                'type' => 'num',
            ],
            'personal_number' => [
                'json' => 'PersonalNumber',
                'type' => 'text',
            ],
            'active_delivery_address_id' => [
                'json' => 'ActiveDeliveryAddressId',
                'type' => 'int',
            ],
            'date_for_transition_to_actual_contact' => [
                'json' => 'DateForTransitionToActualContact',
                'type' => 'date',
            ],
        ],
    ],
    'DeliveryAddress' => [
        'table' => 'erp_delivery_addresses',
        'fields' => [
            'id' => [
                'json' => 'Id',
                'type' => 'int',
            ],
            'addressee' => [
                'json' => 'Addressee',
                'type' => 'text',
            ],
            'field1' => [
                'json' => 'Field1',
                'type' => 'text',
            ],
            'field2' => [
                'json' => 'Field2',
                'type' => 'text',
            ],
            'field3' => [
                'json' => 'Field3',
                'type' => 'text',
            ],
            'field4' => [
                'json' => 'Field4',
                'type' => 'text',
            ],
            'field5' => [
                'json' => 'Field5',
                'type' => 'text',
            ],
            'locality' => [
                'json' => 'Locality',
                'type' => 'text',
            ],
            'region' => [
                'json' => 'Region',
                'type' => 'text',
            ],
            'postal_code' => [
                'json' => 'PostalCode',
                'type' => 'text',
            ],
            'country_id' => [
                'json' => 'CountryId',
                'type' => 'int',
            ],
        ],
    ],
    'DeliveryContact' => [
        'table' => 'erp_delivery_contacts',
        'fields' => [
            'id' => [
                'json' => 'Id',
                'type' => 'int',
            ],
            'communication_address_value' => [
                'json' => 'CommunicationAddressValue',
                'type' => 'text',
            ],
            'remarks' => [
                'json' => 'Remarks',
                'type' => 'text',
            ],
            'recipient_of' => [
                'json' => 'RecipientOf',
                'type' => 'int',
            ],
            'type' => [
                'json' => 'Type',
                'type' => 'int',
            ],
            'type_name' => [
                'json' => 'TypeName',
                'type' => 'text',
            ],
            'contact_name' => [
                'json' => 'ContactName',
                'type' => 'text',
            ],
            'customer_id' => [
                'json' => 'CustomerId',
                'type' => 'int',
            ],
        ],
    ],
    'DeliveryMethod' => [
        'table' => 'erp_delivery_methods',
        'fields' => [
            'id' => [
                'json' => 'Id',
                'type' => 'int',
            ],
            'is_default' => [
                'json' => 'IsDefault',
                'type' => 'bool',
            ],
            'number' => [
                'json' => 'Number',
                'type' => 'text',
            ],
            'description' => [
                'json' => 'Description',
                'type' => 'text',
            ],
            'shipping_service_id' => [
                'json' => 'ShippingServiceId',
                'type' => 'int',
            ],
            'shipping_template_id' => [
                'json' => 'ShippingTemplateId',
                'type' => 'int',
            ],
            'supplier_id' => [
                'json' => 'SupplierId',
                'type' => 'int',
            ],
            'code' => [
                'json' => 'Code',
                'type' => 'text',
            ],
        ],
    ],
    'Department' => [
        'table' => 'erp_departments',
        'fields' => [
            'id' => [
                'json' => 'Id',
                'type' => 'int',
            ],
            'code' => [
                'json' => 'Code',
                'type' => 'text',
            ],
            'description' => [
                'json' => 'Description',
                'type' => 'text',
            ],
            'name' => [
                'json' => 'Name',
                'type' => 'text',
            ],
            'department_id' => [
                'json' => 'DepartmentId',
                'type' => 'int',
            ],
        ],
    ],
    'ExtraField' => [
        'table' => 'erp_extra_fields',
        'fields' => [
            'id' => [
                'json' => 'Id',
                'type' => 'int',
            ],
            'parent_id' => [
                'json' => 'ParentId',
                'type' => 'int',
            ],
            'type' => [
                'json' => 'Type',
                'type' => 'int',
            ],
            'identifier' => [
                'json' => 'Identifier',
                'type' => 'text',
            ],
            'string_value' => [
                'json' => 'StringValue',
                'type' => 'text',
            ],
            'comment_id' => [
                'json' => 'CommentId',
                'type' => 'int',
            ],
        ],
    ],
    'Invoice' => [
        'table' => 'erp_invoices',
        'fields' => [
            'id' => [
                'json' => 'Id',
                'type' => 'int',
            ],
            'invoice_id' => [
                'json' => 'InvoiceId',
                'type' => 'int',
            ],
            'invoice_number' => [
                'json' => 'InvoiceNumber',
                'type' => 'int',
            ],
            'invoice_date' => [
                'json' => 'InvoiceDate',
                'type' => 'date',
            ],
            'customer_id' => [
                'json' => 'CustomerId',
                'type' => 'int',
            ],
            'delivery_row_id' => [
                'json' => 'DeliveryRowId',
                'type' => 'int',
            ],
            'customer_code' => [
                'json' => 'CustomerCode',
                'type' => 'text',
            ],
            'customer_group' => [
                'json' => 'CustomerGroup',
                'type' => 'int',
            ],
            'customer_order_id' => [
                'json' => 'CustomerOrderId',
                'type' => 'int',
            ],
            'customer_order_number' => [
                'json' => 'CustomerOrderNumber',
                'type' => 'text',
            ],
            'order_type' => [
                'json' => 'OrderType',
                'type' => 'text',
            ],
            'ordered_quantity' => [
                'json' => 'OrderedQuantity',
                'type' => 'num',
            ],
            'invoiced_quantity' => [
                'json' => 'InvoicedQuantity',
                'type' => 'num',
            ],
            'price' => [
                'json' => 'Price',
                'type' => 'num',
            ],
            'discount' => [
                'json' => 'Discount',
                'type' => 'num',
            ],
            'part_id' => [
                'json' => 'PartId',
                'type' => 'int',
            ],
            'part_number' => [
                'json' => 'PartNumber',
                'type' => 'text',
            ],
            'unit_code' => [
                'json' => 'UnitCode',
                'type' => 'text',
            ],
            'product_group_number' => [
                'json' => 'ProductGroupNumber',
                'type' => 'text',
            ],
            'currency_code' => [
                'json' => 'CurrencyCode',
                'type' => 'text',
            ],
            'exchange_rate' => [
                'json' => 'ExchangeRate',
                'type' => 'num',
            ],
            'seller' => [
                'json' => 'Seller',
                'type' => 'text',
            ],
            'account_number' => [
                'json' => 'AccountNumber',
                'type' => 'text',
            ],
            'user_id' => [
                'json' => 'UserId',
                'type' => 'int',
            ],
            'user_name' => [
                'json' => 'UserName',
                'type' => 'text',
            ],
            'conversion_factor' => [
                'json' => 'ConversionFactor',
                'type' => 'num',
            ],
            'order_row_type' => [
                'json' => 'OrderRowType',
                'type' => 'int',
            ],
        ],
    ],
    'Order' => [
        'table' => 'erp_orders',
        'fields' => [
            'id' => [
                'json' => 'Id',
                'type' => 'int',
            ],
            'business_contact_order_number' => [
                'json' => 'BusinessContactOrderNumber',
                'type' => 'text',
            ],
            'delivery_method_id' => [
                'json' => 'DeliveryMethodId',
                'type' => 'int',
            ],
            'delivery_term_id' => [
                'json' => 'DeliveryTermId',
                'type' => 'int',
            ],
            'delivery_address_id' => [
                'json' => 'DeliveryAddressId',
                'type' => 'int',
            ],
            'mailing_address_id' => [
                'json' => 'MailingAddressId',
                'type' => 'int',
            ],
            'payment_term_id' => [
                'json' => 'PaymentTermId',
                'type' => 'int',
            ],
            'order_date' => [
                'json' => 'OrderDate',
                'type' => 'date',
            ],
            'order_number' => [
                'json' => 'OrderNumber',
                'type' => 'text',
            ],
            'customer_id' => [
                'json' => 'CustomerId',
                'type' => 'int',
            ],
            'vat_number' => [
                'json' => 'VatNumber',
                'type' => 'text',
            ],
            'our_reference_id' => [
                'json' => 'OurReferenceId',
                'type' => 'int',
            ],
            'seller_id' => [
                'json' => 'SellerId',
                'type' => 'int',
            ],
            'vat_group_id' => [
                'json' => 'VatGroupId',
                'type' => 'int',
            ],
            'business_contact_reference_id' => [
                'json' => 'BusinessContactReferenceId',
                'type' => 'int',
            ],
            'consignee_reference_id' => [
                'json' => 'ConsigneeReferenceId',
                'type' => 'int',
            ],
            'external_comment_id' => [
                'json' => 'ExternalCommentId',
                'type' => 'int',
            ],
            'internal_comment_id' => [
                'json' => 'InternalCommentId',
                'type' => 'int',
            ],
            'order_type_id' => [
                'json' => 'OrderTypeId',
                'type' => 'int',
            ],
            'is_credit' => [
                'json' => 'IsCredit',
                'type' => 'bool',
            ],
            'invoice_type' => [
                'json' => 'InvoiceType',
                'type' => 'int',
            ],
            'source_of_alternative_delivery_addresses' => [
                'json' => 'SourceOfAlternativeDeliveryAddresses',
                'type' => 'int',
            ],
            'shipment_payer' => [
                'json' => 'ShipmentPayer',
                'type' => 'int',
            ],
            'comprehensive_invoice_grouping_mode' => [
                'json' => 'ComprehensiveInvoiceGroupingMode',
                'type' => 'int',
            ],
            'status' => [
                'json' => 'Status',
                'type' => 'int',
            ],
            'unpaid_advance_warning_type' => [
                'json' => 'UnpaidAdvanceWarningType',
                'type' => 'int',
            ],
            'transfer_status' => [
                'json' => 'TransferStatus',
                'type' => 'int',
            ],
            'account_group_id' => [
                'json' => 'AccountGroupId',
                'type' => 'int',
            ],
            'purchase_order_id' => [
                'json' => 'PurchaseOrderId',
                'type' => 'int',
            ],
            'is_stock_order' => [
                'json' => 'IsStockOrder',
                'type' => 'bool',
            ],
            'life_cycle_state' => [
                'json' => 'LifeCycleState',
                'type' => 'int',
            ],
            'priority' => [
                'json' => 'Priority',
                'type' => 'int',
            ],
            'send_method' => [
                'json' => 'SendMethod',
                'type' => 'int',
            ],
            'partial_delivery_type' => [
                'json' => 'PartialDeliveryType',
                'type' => 'int',
            ],
            'warehouse_id' => [
                'json' => 'WarehouseId',
                'type' => 'int',
            ],
        ],
    ],
    'OrderDelivery' => [
        'table' => 'erp_order_deliveries',
        'fields' => [
            'id' => [
                'json' => 'Id',
                'type' => 'int',
            ],
            'customer_order_id' => [
                'json' => 'CustomerOrderId',
                'type' => 'int',
            ],
            'customer_order_row_id' => [
                'json' => 'CustomerOrderRowId',
                'type' => 'int',
            ],
            'delivery_date' => [
                'json' => 'DeliveryDate',
                'type' => 'date',
            ],
            'free_text' => [
                'json' => 'FreeText',
                'type' => 'text',
            ],
            'invoice_id' => [
                'json' => 'InvoiceId',
                'type' => 'int',
            ],
            'parent_invoice_id' => [
                'json' => 'ParentInvoiceId',
                'type' => 'int',
            ],
            'pick_list_delivery_date' => [
                'json' => 'PickListDeliveryDate',
                'type' => 'date',
            ],
            'quantity_change_id' => [
                'json' => 'QuantityChangeId',
                'type' => 'int',
            ],
            'invoice_row_id' => [
                'json' => 'InvoiceRowId',
                'type' => 'int',
            ],
        ],
    ],
    'OrderInvoice' => [
        'table' => 'erp_order_invoices',
        'fields' => [
            'id' => [
                'json' => 'Id',
                'type' => 'int',
            ],
            'delivery_note_number' => [
                'json' => 'DeliveryNoteNumber',
                'type' => 'int',
            ],
            'order_delivery_number' => [
                'json' => 'OrderDeliveryNumber',
                'type' => 'text',
            ],
            'delivery_note_creation_date' => [
                'json' => 'DeliveryNoteCreationDate',
                'type' => 'date',
            ],
            'business_contact_order_id' => [
                'json' => 'BusinessContactOrderId',
                'type' => 'int',
            ],
            'active_delivery_address_customer_id' => [
                'json' => 'ActiveDeliveryAddressCustomerId',
                'type' => 'int',
            ],
            'active_delivery_address_supplier_id' => [
                'json' => 'ActiveDeliveryAddressSupplierId',
                'type' => 'int',
            ],
            'business_contact_reference_id' => [
                'json' => 'BusinessContactReferenceId',
                'type' => 'int',
            ],
            'account_group_id' => [
                'json' => 'AccountGroupId',
                'type' => 'int',
            ],
            'our_reference_id' => [
                'json' => 'OurReferenceId',
                'type' => 'int',
            ],
            'seller_id' => [
                'json' => 'SellerId',
                'type' => 'int',
            ],
            'proforma_number' => [
                'json' => 'ProformaNumber',
                'type' => 'int',
            ],
            'proforma_date' => [
                'json' => 'ProformaDate',
                'type' => 'date',
            ],
            'our_reference_name' => [
                'json' => 'OurReferenceName',
                'type' => 'text',
            ],
            'partial_invoice_type' => [
                'json' => 'PartialInvoiceType',
                'type' => 'int',
            ],
            'ledger_id' => [
                'json' => 'LedgerId',
                'type' => 'int',
            ],
            'status' => [
                'json' => 'Status',
                'type' => 'int',
            ],
        ],
    ],
    'OrderPayment' => [
        'table' => 'erp_order_payments',
        'fields' => [
            'id' => [
                'json' => 'Id',
                'type' => 'int',
            ],
            'customer_order_id' => [
                'json' => 'CustomerOrderId',
                'type' => 'int',
            ],
            'invoicing_period' => [
                'json' => 'InvoicingPeriod',
                'type' => 'text',
            ],
            'vat_rate_id' => [
                'json' => 'VatRateId',
                'type' => 'int',
            ],
            'amount' => [
                'json' => 'Amount',
                'type' => 'num',
            ],
            'amount_currency_id' => [
                'json' => 'AmountCurrencyId',
                'type' => 'int',
            ],
            'is_reset' => [
                'json' => 'IsReset',
                'type' => 'bool',
            ],
            'coding_entry_id' => [
                'json' => 'CodingEntryId',
                'type' => 'int',
            ],
            'partial_invoice_type' => [
                'json' => 'PartialInvoiceType',
                'type' => 'int',
            ],
            'part_id' => [
                'json' => 'PartId',
                'type' => 'int',
            ],
            'override_description' => [
                'json' => 'OverrideDescription',
                'type' => 'text',
            ],
            'fraction_of_total' => [
                'json' => 'FractionOfTotal',
                'type' => 'num',
            ],
            'payment_term_id' => [
                'json' => 'PaymentTermId',
                'type' => 'int',
            ],
            'row_index' => [
                'json' => 'RowIndex',
                'type' => 'int',
            ],
        ],
    ],
    'OrderRow' => [
        'table' => 'erp_order_rows',
        'fields' => [
            'id' => [
                'json' => 'Id',
                'type' => 'int',
            ],
            'order_row_type' => [
                'json' => 'OrderRowType',
                'type' => 'int',
            ],
            'part_id' => [
                'json' => 'PartId',
                'type' => 'int',
            ],
            'payment_plan_row_id' => [
                'json' => 'PaymentPlanRowId',
                'type' => 'int',
            ],
            'additional_row_description' => [
                'json' => 'AdditionalRowDescription',
                'type' => 'text',
            ],
            'free_text' => [
                'json' => 'FreeText',
                'type' => 'text',
            ],
            'parent_row_id' => [
                'json' => 'ParentRowId',
                'type' => 'int',
            ],
            'delivery_date' => [
                'json' => 'DeliveryDate',
                'type' => 'date',
            ],
            'ordered_quantity' => [
                'json' => 'OrderedQuantity',
                'type' => 'num',
            ],
            'price' => [
                'json' => 'Price',
                'type' => 'num',
            ],
            'discount' => [
                'json' => 'Discount',
                'type' => 'num',
            ],
            'conversion_factor' => [
                'json' => 'ConversionFactor',
                'type' => 'num',
            ],
            'unit_id' => [
                'json' => 'UnitId',
                'type' => 'int',
            ],
            'vat_rate_id' => [
                'json' => 'VatRateId',
                'type' => 'int',
            ],
            'order_date' => [
                'json' => 'OrderDate',
                'type' => 'date',
            ],
            'parent_order_id' => [
                'json' => 'ParentOrderId',
                'type' => 'int',
            ],
            'desired_delivery_date' => [
                'json' => 'DesiredDeliveryDate',
                'type' => 'date',
            ],
            'creation_context' => [
                'json' => 'CreationContext',
                'type' => 'int',
            ],
            'row_index' => [
                'json' => 'RowIndex',
                'type' => 'int',
            ],
        ],
    ],
    'Payable' => [
        'table' => 'erp_payables',
        'fields' => [
            'id' => [
                'json' => 'Id',
                'type' => 'int',
            ],
            'payment_method_id' => [
                'json' => 'PaymentMethodId',
                'type' => 'int',
            ],
            'suppliers_invoice_number' => [
                'json' => 'SuppliersInvoiceNumber',
                'type' => 'text',
            ],
            'invoice_type' => [
                'json' => 'InvoiceType',
                'type' => 'int',
            ],
            'is_credit' => [
                'json' => 'IsCredit',
                'type' => 'bool',
            ],
            'purchase_order_id' => [
                'json' => 'PurchaseOrderId',
                'type' => 'int',
            ],
            'preliminary_booking_date' => [
                'json' => 'PreliminaryBookingDate',
                'type' => 'date',
            ],
            'voucher_date' => [
                'json' => 'VoucherDate',
                'type' => 'date',
            ],
            'cancel_voucher_date' => [
                'json' => 'CancelVoucherDate',
                'type' => 'date',
            ],
            'supplier_account_group_id' => [
                'json' => 'SupplierAccountGroupId',
                'type' => 'int',
            ],
            'country_id' => [
                'json' => 'CountryId',
                'type' => 'int',
            ],
            'invoice_number' => [
                'json' => 'InvoiceNumber',
                'type' => 'text',
            ],
            'invoice_date' => [
                'json' => 'InvoiceDate',
                'type' => 'date',
            ],
            'due_date' => [
                'json' => 'DueDate',
                'type' => 'date',
            ],
            'invoice_amount' => [
                'json' => 'InvoiceAmount',
                'type' => 'num',
            ],
            'invoice_amount_currency_id' => [
                'json' => 'InvoiceAmountCurrencyId',
                'type' => 'int',
            ],
            'invoice_amount_in_company_currency' => [
                'json' => 'InvoiceAmountInCompanyCurrency',
                'type' => 'num',
            ],
            'invoice_amount_in_company_currency_currency_id' => [
                'json' => 'InvoiceAmountInCompanyCurrencyCurrencyId',
                'type' => 'int',
            ],
            'vat_amount' => [
                'json' => 'VatAmount',
                'type' => 'num',
            ],
            'vat_amount_currency_id' => [
                'json' => 'VatAmountCurrencyId',
                'type' => 'int',
            ],
            'vat_amount_in_company_currency' => [
                'json' => 'VatAmountInCompanyCurrency',
                'type' => 'num',
            ],
            'vat_amount_in_company_currency_currency_id' => [
                'json' => 'VatAmountInCompanyCurrencyCurrencyId',
                'type' => 'int',
            ],
            'rest_amount' => [
                'json' => 'RestAmount',
                'type' => 'num',
            ],
            'rest_amount_currency_id' => [
                'json' => 'RestAmountCurrencyId',
                'type' => 'int',
            ],
            'rest_amount_in_company_currency' => [
                'json' => 'RestAmountInCompanyCurrency',
                'type' => 'num',
            ],
            'ordered_rest_amount' => [
                'json' => 'OrderedRestAmount',
                'type' => 'num',
            ],
            'currency_id' => [
                'json' => 'CurrencyId',
                'type' => 'int',
            ],
            'exchange_rate' => [
                'json' => 'ExchangeRate',
                'type' => 'num',
            ],
            'paid_in_full_date' => [
                'json' => 'PaidInFullDate',
                'type' => 'date',
            ],
            'comment_id' => [
                'json' => 'CommentId',
                'type' => 'int',
            ],
            'reference_number' => [
                'json' => 'ReferenceNumber',
                'type' => 'text',
            ],
            'vat_group_id' => [
                'json' => 'VatGroupId',
                'type' => 'int',
            ],
            'payment_term_id' => [
                'json' => 'PaymentTermId',
                'type' => 'int',
            ],
            'business_contact_id' => [
                'json' => 'BusinessContactId',
                'type' => 'int',
            ],
        ],
    ],
    'PaymentTerm' => [
        'table' => 'erp_payment_terms',
        'fields' => [
            'id' => [
                'json' => 'Id',
                'type' => 'int',
            ],
            'number' => [
                'json' => 'Number',
                'type' => 'text',
            ],
            'description' => [
                'json' => 'Description',
                'type' => 'text',
            ],
            'grace_period_in_days' => [
                'json' => 'GracePeriodInDays',
                'type' => 'int',
            ],
            'add_days_to_eom' => [
                'json' => 'AddDaysToEom',
                'type' => 'int',
            ],
            'is_free_delivery_month' => [
                'json' => 'IsFreeDeliveryMonth',
                'type' => 'bool',
            ],
            'method' => [
                'json' => 'Method',
                'type' => 'int',
            ],
            'is_default' => [
                'json' => 'IsDefault',
                'type' => 'bool',
            ],
            'code' => [
                'json' => 'Code',
                'type' => 'text',
            ],
        ],
    ],
    'Product' => [
        'table' => 'erp_products',
        'fields' => [
            'id' => [
                'json' => 'Id',
                'type' => 'int',
            ],
            'part_number' => [
                'json' => 'PartNumber',
                'type' => 'text',
            ],
            'description' => [
                'json' => 'Description',
                'type' => 'text',
            ],
            'alias' => [
                'json' => 'Alias',
                'type' => 'text',
            ],
            'standard_price' => [
                'json' => 'StandardPrice',
                'type' => 'num',
            ],
            'part_template_id' => [
                'json' => 'PartTemplateId',
                'type' => 'int',
            ],
            'part_code_id' => [
                'json' => 'PartCodeId',
                'type' => 'text',
            ],
        ],
    ],
    'ProductRecord' => [
        'table' => 'erp_product_records',
        'fields' => [
            'id' => [
                'json' => 'Id',
                'type' => 'int',
            ],
            'product_record_type' => [
                'json' => 'ProductRecordType',
                'type' => 'int',
            ],
            'comment_id' => [
                'json' => 'CommentId',
                'type' => 'int',
            ],
            'serial_number' => [
                'json' => 'SerialNumber',
                'type' => 'text',
            ],
            'part_id' => [
                'json' => 'PartId',
                'type' => 'int',
            ],
            'customer_order_id' => [
                'json' => 'CustomerOrderId',
                'type' => 'int',
            ],
            'charge_number' => [
                'json' => 'ChargeNumber',
                'type' => 'text',
            ],
            'registration_no' => [
                'json' => 'RegistrationNo',
                'type' => 'text',
            ],
            'serial_number_with_part_number' => [
                'json' => 'SerialNumberWithPartNumber',
                'type' => 'text',
            ],
            'actual_arrival_date' => [
                'json' => 'ActualArrivalDate',
                'type' => 'date',
            ],
        ],
    ],
    'PurchaseOrder' => [
        'table' => 'erp_purchase_orders',
        'fields' => [
            'id' => [
                'json' => 'Id',
                'type' => 'int',
            ],
            'order_date' => [
                'json' => 'OrderDate',
                'type' => 'date',
            ],
            'order_number' => [
                'json' => 'OrderNumber',
                'type' => 'text',
            ],
            'goods_label' => [
                'json' => 'GoodsLabel',
                'type' => 'text',
            ],
            'our_reference_id' => [
                'json' => 'OurReferenceId',
                'type' => 'text',
            ],
            'our_reference_name' => [
                'json' => 'OurReferenceName',
                'type' => 'text',
            ],
            'from_inquiry_number' => [
                'json' => 'FromInquiryNumber',
                'type' => 'text',
            ],
            'vat_group_id' => [
                'json' => 'VatGroupId',
                'type' => 'text',
            ],
            'business_contact_id' => [
                'json' => 'BusinessContactId',
                'type' => 'text',
            ],
            'account_group_id' => [
                'json' => 'AccountGroupId',
                'type' => 'text',
            ],
        ],
    ],
    'Quotes' => [
        'table' => 'erp_quotes',
        'fields' => [
            'id' => [
                'json' => 'Id',
                'type' => 'int',
            ],
            'payment_term_id' => [
                'json' => 'PaymentTermId',
                'type' => 'int',
            ],
            'payment_term_description' => [
                'json' => 'PaymentTermDescription',
                'type' => 'text',
            ],
            'grace_period_in_days' => [
                'json' => 'GracePeriodInDays',
                'type' => 'int',
            ],
            'delivery_method_id' => [
                'json' => 'DeliveryMethodId',
                'type' => 'int',
            ],
            'delivery_term_id' => [
                'json' => 'DeliveryTermId',
                'type' => 'int',
            ],
            'delivery_term_description' => [
                'json' => 'DeliveryTermDescription',
                'type' => 'text',
            ],
            'delivery_method_description' => [
                'json' => 'DeliveryMethodDescription',
                'type' => 'text',
            ],
            'shipment_payer' => [
                'json' => 'ShipmentPayer',
                'type' => 'int',
            ],
            'business_contact_order_number' => [
                'json' => 'BusinessContactOrderNumber',
                'type' => 'text',
            ],
            'our_reference_id' => [
                'json' => 'OurReferenceId',
                'type' => 'int',
            ],
            'our_reference_name' => [
                'json' => 'OurReferenceName',
                'type' => 'text',
            ],
            'business_contact_reference_id' => [
                'json' => 'BusinessContactReferenceId',
                'type' => 'int',
            ],
            'business_contact_reference_name' => [
                'json' => 'BusinessContactReferenceName',
                'type' => 'text',
            ],
            'goods_label' => [
                'json' => 'GoodsLabel',
                'type' => 'text',
            ],
            'currency_id' => [
                'json' => 'CurrencyId',
                'type' => 'int',
            ],
            'vat_group_id' => [
                'json' => 'VatGroupId',
                'type' => 'int',
            ],
            'warehouse_id' => [
                'json' => 'WarehouseId',
                'type' => 'int',
            ],
            'priority' => [
                'json' => 'Priority',
                'type' => 'int',
            ],
            'project_id' => [
                'json' => 'ProjectId',
                'type' => 'int',
            ],
            'send_method' => [
                'json' => 'SendMethod',
                'type' => 'int',
            ],
            'invoice_printout_method' => [
                'json' => 'InvoicePrintoutMethod',
                'type' => 'int',
            ],
            'internal_comment_id' => [
                'json' => 'InternalCommentId',
                'type' => 'int',
            ],
            'external_comment_id' => [
                'json' => 'ExternalCommentId',
                'type' => 'int',
            ],
            'transport_time' => [
                'json' => 'TransportTime',
                'type' => 'int',
            ],
            'life_cycle_state' => [
                'json' => 'LifeCycleState',
                'type' => 'int',
            ],
            'version' => [
                'json' => 'Version',
                'type' => 'int',
            ],
            'use_forward_rate' => [
                'json' => 'UseForwardRate',
                'type' => 'bool',
            ],
            'exchange_rate' => [
                'json' => 'ExchangeRate',
                'type' => 'num',
            ],
            'customer_id' => [
                'json' => 'CustomerId',
                'type' => 'int',
            ],
            'order_number' => [
                'json' => 'OrderNumber',
                'type' => 'text',
            ],
            'order_date' => [
                'json' => 'OrderDate',
                'type' => 'date',
            ],
            'seller_id' => [
                'json' => 'SellerId',
                'type' => 'int',
            ],
            'delivery_address_id' => [
                'json' => 'DeliveryAddressId',
                'type' => 'int',
            ],
            'mailing_address_id' => [
                'json' => 'MailingAddressId',
                'type' => 'int',
            ],
            'vat_number' => [
                'json' => 'VatNumber',
                'type' => 'text',
            ],
            'status' => [
                'json' => 'Status',
                'type' => 'int',
            ],
            'quote_date' => [
                'json' => 'QuoteDate',
                'type' => 'date',
            ],
            'quote_description' => [
                'json' => 'QuoteDescription',
                'type' => 'text',
            ],
            'latest_contact_date' => [
                'json' => 'LatestContactDate',
                'type' => 'date',
            ],
            'request_for_quote_date' => [
                'json' => 'RequestForQuoteDate',
                'type' => 'date',
            ],
            'validity_time_id' => [
                'json' => 'ValidityTimeId',
                'type' => 'int',
            ],
            'validity_time_description' => [
                'json' => 'ValidityTimeDescription',
                'type' => 'text',
            ],
            'validity_time_in_days' => [
                'json' => 'ValidityTimeInDays',
                'type' => 'int',
            ],
            'valid_through_date' => [
                'json' => 'ValidThroughDate',
                'type' => 'date',
            ],
            'probability_id' => [
                'json' => 'ProbabilityId',
                'type' => 'int',
            ],
            'reason_code_lost_quote_id' => [
                'json' => 'ReasonCodeLostQuoteId',
                'type' => 'int',
            ],
            'reason_lost_comment_id' => [
                'json' => 'ReasonLostCommentId',
                'type' => 'int',
            ],
            'order_type_id' => [
                'json' => 'OrderTypeId',
                'type' => 'int',
            ],
            'preliminary' => [
                'json' => 'Preliminary',
                'type' => 'bool',
            ],
            'currency_exchange_type_id' => [
                'json' => 'CurrencyExchangeTypeId',
                'type' => 'int',
            ],
            'delivery_time_id' => [
                'json' => 'DeliveryTimeId',
                'type' => 'int',
            ],
            'delivery_time_in_days' => [
                'json' => 'DeliveryTimeInDays',
                'type' => 'int',
            ],
            'account_group_id' => [
                'json' => 'AccountGroupId',
                'type' => 'int',
            ],
            'category_string' => [
                'json' => 'CategoryString',
                'type' => 'text',
            ],
            'destination_for_delivery_term' => [
                'json' => 'DestinationForDeliveryTerm',
                'type' => 'text',
            ],
            'edited_order_amount_excluding_vat' => [
                'json' => 'EditedOrderAmountExcludingVat',
                'type' => 'num',
            ],
            'edited_order_amount_excluding_vat_currency_id' => [
                'json' => 'EditedOrderAmountExcludingVatCurrencyId',
                'type' => 'int',
            ],
            'edited_order_amount_discount' => [
                'json' => 'EditedOrderAmountDiscount',
                'type' => 'num',
            ],
            'type_of_order_amount_entered' => [
                'json' => 'TypeOfOrderAmountEntered',
                'type' => 'int',
            ],
            'factoring' => [
                'json' => 'Factoring',
                'type' => 'bool',
            ],
            'business_opportunity_id' => [
                'json' => 'BusinessOpportunityId',
                'type' => 'int',
            ],
            'related_quotes_id' => [
                'json' => 'RelatedQuotesId',
                'type' => 'int',
            ],
        ],
    ],
    'Receivable' => [
        'table' => 'erp_receivables',
        'fields' => [
            'id' => [
                'json' => 'Id',
                'type' => 'int',
            ],
            'invoice_type' => [
                'json' => 'InvoiceType',
                'type' => 'int',
            ],
            'latest_reminder_date' => [
                'json' => 'LatestReminderDate',
                'type' => 'date',
            ],
            'payment_reminder' => [
                'json' => 'PaymentReminder',
                'type' => 'bool',
            ],
            'partial_invoice_type' => [
                'json' => 'PartialInvoiceType',
                'type' => 'int',
            ],
            'number_of_payment_reminders' => [
                'json' => 'NumberOfPaymentReminders',
                'type' => 'int',
            ],
            'collection_date' => [
                'json' => 'CollectionDate',
                'type' => 'date',
            ],
            'collection' => [
                'json' => 'Collection',
                'type' => 'bool',
            ],
            'interest_type' => [
                'json' => 'InterestType',
                'type' => 'int',
            ],
            'status' => [
                'json' => 'Status',
                'type' => 'int',
            ],
            'late_payment_fee' => [
                'json' => 'LatePaymentFee',
                'type' => 'num',
            ],
            'business_contact_id' => [
                'json' => 'BusinessContactId',
                'type' => 'int',
            ],
            'cancel_comment_id' => [
                'json' => 'CancelCommentId',
                'type' => 'int',
            ],
            'cancel_date' => [
                'json' => 'CancelDate',
                'type' => 'date',
            ],
            'invoice_number' => [
                'json' => 'InvoiceNumber',
                'type' => 'int',
            ],
            'invoice_date' => [
                'json' => 'InvoiceDate',
                'type' => 'date',
            ],
            'due_date' => [
                'json' => 'DueDate',
                'type' => 'date',
            ],
            'is_credit' => [
                'json' => 'IsCredit',
                'type' => 'bool',
            ],
            'invoice_amount' => [
                'json' => 'InvoiceAmount',
                'type' => 'num',
            ],
            'invoice_amount_currency_id' => [
                'json' => 'InvoiceAmountCurrencyId',
                'type' => 'int',
            ],
            'invoice_amount_in_company_currency' => [
                'json' => 'InvoiceAmountInCompanyCurrency',
                'type' => 'num',
            ],
            'vat_amount' => [
                'json' => 'VatAmount',
                'type' => 'num',
            ],
            'vat_amount_currency_id' => [
                'json' => 'VatAmountCurrencyId',
                'type' => 'int',
            ],
            'rest_amount' => [
                'json' => 'RestAmount',
                'type' => 'num',
            ],
            'rest_amount_currency_id' => [
                'json' => 'RestAmountCurrencyId',
                'type' => 'int',
            ],
            'ordered_rest_amount' => [
                'json' => 'OrderedRestAmount',
                'type' => 'num',
            ],
            'paid_in_full_date' => [
                'json' => 'PaidInFullDate',
                'type' => 'date',
            ],
            'exchange_rate' => [
                'json' => 'ExchangeRate',
                'type' => 'num',
            ],
        ],
    ],
    'Seller' => [
        'table' => 'erp_sellers',
        'fields' => [
            'id' => [
                'json' => 'Id',
                'type' => 'int',
            ],
            'first_name' => [
                'json' => 'FirstName',
                'type' => 'text',
            ],
            'last_name' => [
                'json' => 'LastName',
                'type' => 'text',
            ],
            'phone_number' => [
                'json' => 'PhoneNumber',
                'type' => 'text',
            ],
            'email_address' => [
                'json' => 'EmailAddress',
                'type' => 'text',
            ],
            'department_id' => [
                'json' => 'DepartmentId',
                'type' => 'int',
            ],
            'employee_number' => [
                'json' => 'EmployeeNumber',
                'type' => 'text',
            ],
        ],
    ],
    'StockTransaction' => [
        'table' => 'erp_stock_transactions',
        'fields' => [
            'id' => [
                'json' => 'Id',
                'type' => 'int',
            ],
            'part_number' => [
                'json' => 'PartNumber',
                'type' => 'text',
            ],
            'part_id' => [
                'json' => 'PartId',
                'type' => 'int',
            ],
            'balance_change' => [
                'json' => 'BalanceChange',
                'type' => 'num',
            ],
            'balance_on_part_after_change' => [
                'json' => 'BalanceOnPartAfterChange',
                'type' => 'num',
            ],
            'balance_on_location_after_change' => [
                'json' => 'BalanceOnLocationAfterChange',
                'type' => 'num',
            ],
            'delivery_date' => [
                'json' => 'DeliveryDate',
                'type' => 'date',
            ],
            'location_name' => [
                'json' => 'LocationName',
                'type' => 'text',
            ],
            'business_transaction_id' => [
                'json' => 'BusinessTransactionId',
                'type' => 'int',
            ],
            'part_location_id' => [
                'json' => 'PartLocationId',
                'type' => 'int',
            ],
            'product_record_id' => [
                'json' => 'ProductRecordId',
                'type' => 'int',
            ],
            'batch_number' => [
                'json' => 'BatchNumber',
                'type' => 'text',
            ],
            'order_number' => [
                'json' => 'OrderNumber',
                'type' => 'text',
            ],
        ],
    ],
    'Supplier' => [
        'table' => 'erp_suppliers',
        'fields' => [
            'id' => [
                'json' => 'Id',
                'type' => 'int',
            ],
            'supplier_code' => [
                'json' => 'SupplierCode',
                'type' => 'text',
            ],
            'name' => [
                'json' => 'Name',
                'type' => 'text',
            ],
            'alternative_name' => [
                'json' => 'AlternativeName',
                'type' => 'text',
            ],
            'corporation_identification_number' => [
                'json' => 'CorporationIdentificationNumber',
                'type' => 'text',
            ],
            'vat_number' => [
                'json' => 'VatNumber',
                'type' => 'text',
            ],
            'date_for_transition_to_actual_contact' => [
                'json' => 'DateForTransitionToActualContact',
                'type' => 'date',
            ],
            'alias' => [
                'json' => 'Alias',
                'type' => 'text',
            ],
            'purchase_account_id' => [
                'json' => 'PurchaseAccountId',
                'type' => 'int',
            ],
            'last_change' => [
                'json' => 'LastChange',
                'type' => 'text',
            ],
        ],
    ],
    'Unit' => [
        'table' => 'erp_units',
        'fields' => [
            'id' => [
                'json' => 'Id',
                'type' => 'int',
            ],
            'code' => [
                'json' => 'Code',
                'type' => 'text',
            ],
            'description' => [
                'json' => 'Description',
                'type' => 'text',
            ],
            'number' => [
                'json' => 'Number',
                'type' => 'text',
            ],
        ],
    ],
    'Vat' => [
        'table' => 'erp_vats',
        'fields' => [
            'id' => [
                'json' => 'Id',
                'type' => 'int',
            ],
            'number' => [
                'json' => 'Number',
                'type' => 'int',
            ],
            'description' => [
                'json' => 'Description',
                'type' => 'text',
            ],
            'percentage' => [
                'json' => 'Percentage',
                'type' => 'num',
            ],
        ],
    ],
    'VatGroup' => [
        'table' => 'erp_vat_groups',
        'fields' => [
            'id' => [
                'json' => 'Id',
                'type' => 'int',
            ],
            'number' => [
                'json' => 'Number',
                'type' => 'int',
            ],
            'description' => [
                'json' => 'Description',
                'type' => 'text',
            ],
            'default_vat_rate_id' => [
                'json' => 'DefaultVatRateId',
                'type' => 'int',
            ],
        ],
    ],
    'Voucher' => [
        'table' => 'erp_vouchers',
        'fields' => [
            'id' => [
                'json' => 'Id',
                'type' => 'int',
            ],
            'series_id' => [
                'json' => 'SeriesId',
                'type' => 'int',
            ],
            'number' => [
                'json' => 'Number',
                'type' => 'text',
            ],
            'text' => [
                'json' => 'Text',
                'type' => 'text',
            ],
            'voucher_date' => [
                'json' => 'VoucherDate',
                'type' => 'date',
            ],
            'connected_voucher_id' => [
                'json' => 'ConnectedVoucherId',
                'type' => 'int',
            ],
            'connection_type' => [
                'json' => 'ConnectionType',
                'type' => 'text',
            ],
            'is_preliminary' => [
                'json' => 'IsPreliminary',
                'type' => 'bool',
            ],
            'bundle_number' => [
                'json' => 'BundleNumber',
                'type' => 'int',
            ],
            'comment_id' => [
                'json' => 'CommentId',
                'type' => 'int',
            ],
            'accounts_payable_id' => [
                'json' => 'AccountsPayableId',
                'type' => 'int',
            ],
            'accounts_receivable_ledger_id' => [
                'json' => 'AccountsReceivableLedgerId',
                'type' => 'int',
            ],
            'accrual_accounting_row_id' => [
                'json' => 'AccrualAccountingRowId',
                'type' => 'int',
            ],
            'stock_transaction_log_ledger_id' => [
                'json' => 'StockTransactionLogLedgerId',
                'type' => 'int',
            ],
            'manufacturing_order_log_ledger_id' => [
                'json' => 'ManufacturingOrderLogLedgerId',
                'type' => 'int',
            ],
            'vat_booking_id' => [
                'json' => 'VatBookingId',
                'type' => 'int',
            ],
            'correction_booking_id' => [
                'json' => 'CorrectionBookingId',
                'type' => 'int',
            ],
        ],
    ],
    'VoucherRow' => [
        'table' => 'erp_voucher_rows',
        'fields' => [
            'id' => [
                'json' => 'Id',
                'type' => 'int',
            ],
            'entry_identifier' => [
                'json' => 'EntryIdentifier',
                'type' => 'text',
            ],
            'voucher_id' => [
                'json' => 'VoucherId',
                'type' => 'int',
            ],
            'parent_voucher_date' => [
                'json' => 'ParentVoucherDate',
                'type' => 'date',
            ],
            'exchange_rate' => [
                'json' => 'ExchangeRate',
                'type' => 'num',
            ],
            'debit_in_company_currency' => [
                'json' => 'DebitInCompanyCurrency',
                'type' => 'num',
            ],
            'debit_in_company_currency_currency_id' => [
                'json' => 'DebitInCompanyCurrencyCurrencyId',
                'type' => 'int',
            ],
            'credit_in_company_currency' => [
                'json' => 'CreditInCompanyCurrency',
                'type' => 'num',
            ],
            'credit_in_company_currency_currency_id' => [
                'json' => 'CreditInCompanyCurrencyCurrencyId',
                'type' => 'int',
            ],
            'credit' => [
                'json' => 'Credit',
                'type' => 'num',
            ],
            'credit_currency_id' => [
                'json' => 'CreditCurrencyId',
                'type' => 'int',
            ],
            'debit' => [
                'json' => 'Debit',
                'type' => 'num',
            ],
            'debit_currency_id' => [
                'json' => 'DebitCurrencyId',
                'type' => 'int',
            ],
            'quantity' => [
                'json' => 'Quantity',
                'type' => 'num',
            ],
            'balance_id' => [
                'json' => 'BalanceId',
                'type' => 'int',
            ],
        ],
    ],
    'Warehouse' => [
        'table' => 'erp_warehouses',
        'fields' => [
            'id' => [
                'json' => 'Id',
                'type' => 'int',
            ],
            'code' => [
                'json' => 'Code',
                'type' => 'text',
            ],
            'name' => [
                'json' => 'Name',
                'type' => 'text',
            ],
        ],
    ],
];
