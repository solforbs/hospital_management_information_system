<?php

namespace App\Services\Stores;

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\PurchaseOrder;
use App\Services\Audit\AuditLogger;
use App\Services\Numbering\NumberSequenceService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * General stores (non-pharmacy): purchase orders with separation of duties
 * (the creator cannot approve), goods received against the order, issues
 * to departments, and adjustments — every stock change is a ledger entry
 * and stock rows are locked while they change.
 */
class StoresService
{
    public function __construct(protected NumberSequenceService $numbers, protected AuditLogger $audit) {}

    /**
     * @param  array<int, array{inventory_item_id: int, quantity: float, unit_cost: float}>  $lines
     */
    public function createPurchaseOrder(int $supplierId, array $lines, ?string $notes = null): PurchaseOrder
    {
        $lines = array_values(array_filter($lines, fn ($l) => (float) ($l['quantity'] ?? 0) > 0));

        if (! $lines) {
            throw ValidationException::withMessages(['lines' => __('Add at least one item with a quantity.')]);
        }

        return DB::transaction(function () use ($supplierId, $lines, $notes) {
            $po = PurchaseOrder::create([
                'supplier_id' => $supplierId,
                'po_number' => $this->numbers->next('PO', app(TenantContext::class)->id()),
                'status' => 'draft',
                'notes' => $notes,
                'created_by' => Auth::id(),
            ]);

            $total = 0;
            foreach ($lines as $l) {
                $item = InventoryItem::findOrFail($l['inventory_item_id']);
                $po->lines()->create(['tenant_id' => $po->tenant_id, 'inventory_item_id' => $item->id, 'quantity' => $l['quantity'], 'unit_cost' => $l['unit_cost']]);
                $total += (float) $l['quantity'] * (float) $l['unit_cost'];
            }

            $po->update(['total' => round($total, 2)]);
            $this->audit->log('stores.po_created', $po, ['total' => $total]);

            return $po;
        });
    }

    public function approve(PurchaseOrder $po): PurchaseOrder
    {
        if ($po->status !== 'draft') {
            throw ValidationException::withMessages(['po' => __('Only draft orders can be approved.')]);
        }

        if ((int) $po->created_by === (int) Auth::id()) {
            throw ValidationException::withMessages(['po' => __('An order must be approved by someone other than the person who raised it.')]);
        }

        $po->update(['status' => 'approved', 'approved_by' => Auth::id(), 'approved_at' => now()]);
        $this->audit->log('stores.po_approved', $po);

        return $po;
    }

    /**
     * Goods received note: quantities per PO line (cannot exceed what is outstanding).
     *
     * @param  array<int, float>  $received  line id => quantity received now
     */
    public function receive(PurchaseOrder $po, array $received, ?string $deliveryNote): PurchaseOrder
    {
        if (! in_array($po->status, ['approved', 'partially_received'], true)) {
            throw ValidationException::withMessages(['po' => __('Goods can only be received against an approved order.')]);
        }

        return DB::transaction(function () use ($po, $received, $deliveryNote) {
            $any = false;

            foreach ($po->lines as $line) {
                $qty = round((float) ($received[$line->id] ?? 0), 2);
                if ($qty <= 0) {
                    continue;
                }

                $outstanding = (float) $line->quantity - (float) $line->quantity_received;
                if ($qty > $outstanding + 0.001) {
                    throw ValidationException::withMessages(["received.{$line->id}" => __('Only :n outstanding for :item.', ['n' => $outstanding, 'item' => $line->item->name])]);
                }

                $item = InventoryItem::lockForUpdate()->findOrFail($line->inventory_item_id);
                $item->update(['stock_on_hand' => (float) $item->stock_on_hand + $qty, 'last_unit_cost' => $line->unit_cost]);
                $line->update(['quantity_received' => (float) $line->quantity_received + $qty]);

                InventoryMovement::create([
                    'inventory_item_id' => $item->id, 'type' => 'receipt', 'quantity' => $qty, 'unit_cost' => $line->unit_cost,
                    'purchase_order_id' => $po->id, 'reference' => $deliveryNote, 'user_id' => Auth::id(),
                ]);
                $any = true;
            }

            if (! $any) {
                throw ValidationException::withMessages(['received' => __('Enter the quantities received.')]);
            }

            $complete = $po->lines()->get()->every(fn ($l) => (float) $l->quantity_received >= (float) $l->quantity);
            $po->update(['status' => $complete ? 'received' : 'partially_received']);
            $this->audit->log('stores.goods_received', $po, ['delivery_note' => $deliveryNote]);

            return $po;
        });
    }

    public function issue(InventoryItem $item, float $quantity, ?int $departmentId, ?string $reason): InventoryMovement
    {
        return DB::transaction(function () use ($item, $quantity, $departmentId, $reason) {
            $item = InventoryItem::lockForUpdate()->findOrFail($item->id);

            if ($quantity <= 0 || $quantity > (float) $item->stock_on_hand) {
                throw ValidationException::withMessages(['quantity' => __('Only :n :unit in stock.', ['n' => (float) $item->stock_on_hand, 'unit' => $item->unit])]);
            }

            $item->update(['stock_on_hand' => (float) $item->stock_on_hand - $quantity]);

            return InventoryMovement::create([
                'inventory_item_id' => $item->id, 'type' => 'issue', 'quantity' => -$quantity, 'unit_cost' => $item->last_unit_cost,
                'department_id' => $departmentId, 'reason' => $reason, 'user_id' => Auth::id(),
            ]);
        });
    }

    /** Stock-take correction (positive or negative); a reason is mandatory. */
    public function adjust(InventoryItem $item, float $countedQuantity, string $reason): ?InventoryMovement
    {
        if (mb_strlen(trim($reason)) < 3) {
            throw ValidationException::withMessages(['reason' => __('Give the reason for the adjustment.')]);
        }

        return DB::transaction(function () use ($item, $countedQuantity, $reason) {
            $item = InventoryItem::lockForUpdate()->findOrFail($item->id);
            $delta = round($countedQuantity - (float) $item->stock_on_hand, 2);

            if ($delta == 0.0) {
                return null;
            }

            $item->update(['stock_on_hand' => $countedQuantity]);
            $m = InventoryMovement::create(['inventory_item_id' => $item->id, 'type' => 'adjustment', 'quantity' => $delta, 'unit_cost' => $item->last_unit_cost, 'reason' => $reason, 'user_id' => Auth::id()]);
            $this->audit->log('stores.adjusted', $item, ['delta' => $delta, 'reason' => $reason]);

            return $m;
        });
    }
}
