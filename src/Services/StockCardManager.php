<?php
namespace ESolution\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ESolution\Inventory\Models\Document;

class StockCardManager
{
    public function generateForDocument(Document $document)
    {
        if (!inv_cfg('enable_stock_cards', false)) {
            return;
        }

        // Kelompokkan per item_id dan branch_id agar tidak muncul dobel di UI
        $grouped = [];
        foreach ($document->lines as $line) {
            $key = $line->item_id . '_' . $line->branch_id;
            
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'item_id' => $line->item_id,
                    'branch_id' => $line->branch_id,
                    'qty' => 0,
                    'totalTrx' => 0,
                    'salesPrice' => $line->meta['harga_jual'] ?? 0,
                    'discount' => $line->meta['diskon'] ?? 0,
                    'nettPrice' => $line->meta['harga_nett'] ?? ($line->unit_cost ?? 0),
                    'line_ids' => [],
                ];
            }
            
            $grouped[$key]['qty'] += $line->qty;
            $nettPrice = $line->meta['harga_nett'] ?? ($line->unit_cost ?? 0);
            $grouped[$key]['totalTrx'] += ($line->qty * $nettPrice);
            $grouped[$key]['line_ids'][] = $line->id;
        }

        foreach ($grouped as $group) {
            $this->processGroup($document, $group);
        }
    }

    private function processGroup(Document $document, array $group)
    {
        $lastCard = DB::table('inv_stock_cards')
            ->where('item_id', $group['item_id'])
            ->where('branch_id', $group['branch_id'])
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        $qty = $group['qty'];
        $totalTrx = $group['totalTrx'];
        
        $prevBalanceQty = $lastCard ? (float) $lastCard->balance_qty : 0;
        $prevBalanceAmount = $lastCard ? (float) $lastCard->balance_amount : 0;
        $prevAvgCost = $lastCard ? (float) $lastCard->average_cost : 0;
        $prevRunningSales = $lastCard ? (float) $lastCard->running_total_sales : 0;
        
        $newBalanceQty = 0;
        $newBalanceAmount = 0;
        $newAvgCost = 0;
        
        $cogs = 0;
        $profitUnit = 0;
        $profitAmount = 0;
        $runningSales = $prevRunningSales;
        $runningAvgSales = $lastCard ? (float) $lastCard->running_avg_sales : 0;
        
        $direction = 'balance';
        if (in_array($document->type, ['purchase', 'stock_opname', 'transfer_in', 'sales_return'])) {
            $direction = 'in';
            
            // Get actual purchase amount from ledger
            $actualLedgerAmount = DB::table('inv_stock_ledgers')
                ->whereIn('document_line_id', $group['line_ids'])
                ->where('direction', 'in')
                ->sum('amount');
                
            $inAmount = $actualLedgerAmount > 0 ? $actualLedgerAmount : $totalTrx;
            
            $newBalanceQty = $prevBalanceQty + $qty;
            $newBalanceAmount = $prevBalanceAmount + $inAmount;
            $newAvgCost = $newBalanceQty > 0 ? ($newBalanceAmount / $newBalanceQty) : 0;
        } elseif (in_array($document->type, ['sale', 'purchase_return', 'transfer_out'])) {
            $direction = 'out';
            
            // AMBIL HPP ASLI DARI DATABASE (Summary of all racks)
            $actualCogs = DB::table('inv_stock_ledgers')
                ->whereIn('document_line_id', $group['line_ids'])
                ->where('direction', 'out')
                ->sum('amount');
                
            $cogs = $actualCogs; 
            
            $newBalanceQty = $prevBalanceQty - $qty;
            $newBalanceAmount = $prevBalanceAmount - $cogs;
            $newAvgCost = $prevAvgCost; 
            
            if ($document->type === 'sale') {
                $actualUnitCost = $qty > 0 ? ($cogs / $qty) : 0;
                // $profitUnit is an average profit across racks
                $profitUnit = $qty > 0 ? (($totalTrx - $cogs) / $qty) : 0; 
                $profitAmount = $totalTrx - $cogs;
                $runningSales += $totalTrx;

                // Hitung total qty terjual historis untuk item ini
                $pastQtySold = DB::table('inv_stock_cards')
                    ->where('item_id', $group['item_id'])
                    ->where('branch_id', $group['branch_id'])
                    ->where('document_type', 'sale')
                    ->sum('qty');
                
                $cumulativeQty = $pastQtySold + $qty;
                $runningAvgSales = $cumulativeQty > 0 ? ($runningSales / $cumulativeQty) : 0;
            }
        }

        DB::table('inv_stock_cards')->insert([
            'id' => (string) Str::uuid(),
            'item_id' => $group['item_id'],
            'branch_id' => $group['branch_id'],
            'date' => $document->date,
            'document_ref' => $document->ref,
            'document_type' => $document->type,
            'direction' => $direction,
            'description' => $document->meta['description'] ?? null,
            
            'qty' => $qty,
            'sales_price' => $group['salesPrice'],
            'discount_amount' => $group['discount'],
            'nett_price' => $qty > 0 ? ($totalTrx / $qty) : 0, // Avg nett price
            'total_trx' => $totalTrx,
            
            'average_cost' => $newAvgCost,
            'balance_qty' => $newBalanceQty,
            'balance_amount' => $newBalanceAmount,
            
            'cogs' => $cogs,
            'profit_unit' => $profitUnit,
            'profit_amount' => $profitAmount,
            'running_total_sales' => $runningSales,
            'running_avg_sales' => $runningAvgSales,
            
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
