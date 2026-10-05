# Laravel Inventory Retail

`elgibor-solution/laravel-inventory-retail` is an optional vertical for
`elgibor-solution/laravel-inventory`. It owns only the
`ESolution\InventoryRetail` namespace and `invr_*` tables.

## Installation

```bash
composer require elgibor-solution/laravel-inventory-retail
php artisan vendor:publish --tag=inventory-retail-config
php artisan migrate
```

For this monorepo development layout, register `packages/retail` as a Composer
path repository in the host application before requiring `@dev`.

Enable Consignment in `config/inventory-retail.php`:

```php
'consignment' => [
    'enabled' => true,
    'settlement' => ['periodicity' => 'monthly'],
],
```

## Variant matrix

Create a Product Family, its axes, and values, then generate the cartesian
matrix. Every result is a distinct Core Item with independent Ledger, Costing,
Stock Card, and Reservation state.

```php
$items = app(VariantMatrixGenerator::class)->generate($family);
```

Generation is deterministic and retry-safe. Existing matching combinations are
returned; an SKU collision with unrelated Item data is rejected. Item inserts
are chunked according to `inventory-retail.variant_matrix.insert_chunk_size`.

## Consignment

Configure supplier terms at Item scope or at a more specific storage location:

```php
$term = app(ConsignmentTermsService::class)->configure(
    itemId: $itemId,
    supplierPartyType: Supplier::class,
    supplierPartyId: (string) $supplierId,
    locationId: null,
    referenceUnitCost: 5000,
    periodicity: 'monthly',
);
```

The service registers the Core `inventory_model=consignment` policy override.
Location overrides win over Item-only overrides. A Consignment receipt:

- posts ordinary physical Ledger and Stock Card quantities;
- may retain a reference cost for reporting;
- reports `ownedValue = 0` through `ConsignmentInventoryService`;
- does not call Core's Accounting Bridge for ownership transfer.

A `sales_delivery` remains an ordinary Goods Issue. After commit, Retail records
one `invr_consignment_settlements` obligation per sold Document Line. Retrying a
sale cannot duplicate it. Retail never posts a settlement journal.

Projects may mark obligations settled without accounting side effects:

```bash
php artisan inventory-retail:consignment:settle --through=2026-09-30
```

## POS and E-Commerce

- POS posts an ordinary `sales_delivery` with no Reservation.
- E-Commerce uses Core's existing Phase 4 flow: reserve at checkout, then post
  `sales_delivery` with `ReservationConsumptionData` at fulfillment.

Retail contains no pricing, discount, till, storefront, Sales Order, Purchase
Order, supplier accounting, or sibling-vertical logic.
