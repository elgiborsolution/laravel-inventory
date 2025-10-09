<?php
namespace ESolution\Inventory\Enums;

enum DocumentType: string {
    case PURCHASE = 'purchase';
    case SALE = 'sale';
    case PURCHASE_RETURN = 'purchase_return';
    case SALES_RETURN = 'sales_return';
    case STOCK_OPNAME = 'stock_opname';
    case CONSIGNMENT = 'consignment';
    case TRANSFER_RACK = 'transfer_rack';
    case TRANSFER_WAREHOUSE = 'transfer_warehouse';
    case TRANSFER_BRANCH = 'transfer_branch';
}
