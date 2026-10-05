# Sales and Purchasing Integration

Inventory Core does not own Sales Order or Purchase Order tables. Application
code identifies those records with `sourceType` and `sourceId`, while
`partyType` and `partyId` independently identify the customer or supplier.

## Sales reservation lifecycle

Reserve stock when the application confirms a Sales Order:

```php
use ESolution\Inventory\Facades\Inventory;

$reservation = Inventory::reserve(
    itemId: $line->item_id,
    qty: $line->qty,
    warehouseId: $order->warehouse_id,
    sourceType: $order::class,
    sourceId: (string) $order->getKey(),
);
```

Release its remaining quantity when demand is cancelled:

```php
Inventory::release($reservation->id);
```

Reservation methods change availability only. They do not write Ledger or Cost
Layer rows and do not invoke Accounting or Approval bridges.

## Atomic fulfillment

Attach every reservation consumption to the corresponding outbound document
line. Posting, costing, Ledger writes, Stock Card refresh, and reservation
consumption then share the Posting Engine transaction:

```php
use ESolution\Inventory\DTO\DocumentData;
use ESolution\Inventory\DTO\LineData;
use ESolution\Inventory\DTO\ReservationConsumptionData;
use ESolution\Inventory\Facades\Inventory;

$document = Inventory::post(new DocumentData(
    type: 'sales_delivery',
    organizationId: $order->organization_id,
    trxDate: now()->toDateString(),
    externalId: "shipment:{$shipment->id}",
    sourceType: $order::class,
    sourceId: (string) $order->getKey(),
    partyType: $order->customer::class,
    partyId: (string) $order->customer->getKey(),
    lines: [
        new LineData(
            itemId: $line->item_id,
            uomId: $line->uom_id,
            warehouseId: $order->warehouse_id,
            qty: $shippedQty,
        ),
    ],
    reservationConsumptions: [
        new ReservationConsumptionData(
            reservationId: $reservation->id,
            lineNo: 1,
            qty: $shippedQty,
            idempotencyKey: "shipment-line:{$shipmentLine->id}",
        ),
    ],
));
```

The fulfillment key is unique within one Reservation. Retrying the same
document and payload returns the existing result; reusing a fulfillment key
with a different quantity or line is rejected.

The Reservation item, warehouse, `source_type`, and `source_id` must match the
linked Goods Issue line and Document. Linked quantities cannot exceed either
the Reservation remainder or the Document Line quantity.

When Approval pauses the Document, the consumption instruction remains in the
Document metadata and no Reservation progress changes. Approval resume executes
the stock effects and linked consumption exactly once in the same transaction.

Partial shipments use a new Document `externalId` and a new fulfillment key for
each shipment. A Reservation remains `active` until all quantity is consumed or
released.

Walk-in sales remain valid: omit `reservationConsumptions` and post an ordinary
Goods Issue.

## Purchasing

Purchasing never uses Reservation. When goods physically arrive, post a normal
`purchase_receipt` with the Purchase Order as source and the Supplier as party.
PO approval and workflow remain application-owned.

## Availability

Read current warehouse availability with:

```php
$availability = Inventory::availability($itemId, $warehouseId);

$availability->onHandQty;
$availability->reservedQty;
$availability->lockedQty;
$availability->availableQty();
```

The formula is `on hand - active reservation remainder - active stock locks`.
Reservations are permissive by default to support backorders. To reject a
Reservation that exceeds current availability, add `reservation` to:

```php
'policies' => [
    'negative_stock' => [
        'mode' => 'block',
        'applies_to' => ['goods_issue', 'reservation'],
    ],
],
```

Application authorization and all SO/PO schema, status, approval, and business
rules remain outside Inventory Core.
