<?php

if (!defined('ABSPATH')) exit;

class WCSSD_StockUpdater {
  public function set_stock_and_price($product, int $qty, string $price) {
    $product->set_manage_stock(true);
    $product->set_stock_quantity($qty);
    $product->set_stock_status($qty > 0 ? 'instock' : 'outofstock');
    $product->set_regular_price($price);
    $product->save();
  }

  public function set_stock_zero_for_product_id(int $product_id, bool $dry_run, array &$lines) {
    $p = wc_get_product($product_id);
    if (!$p) return;

    $type = $p->get_type();

    if ($type === 'variable') {
      $children = $p->get_children();

      if ($children) {
        foreach ($children as $vid) {
          $v = wc_get_product($vid);
          if (!$v) continue;

          if (!$dry_run) {
            $v->set_manage_stock(true);
            $v->set_stock_quantity(0);
            $v->set_stock_status('outofstock');
            $v->save();
          }
        }
      }

      if (!$dry_run) {
        $p->set_stock_status('outofstock');
        $p->save();
      }

      $lines[] = $dry_run
        ? "[DRY][PREZERO] variable ID={$product_id} -> variations set to 0"
        : "[OK][PREZERO] variable ID={$product_id} -> variations set to 0";
      return;
    }

    if (!$dry_run) {
      $p->set_manage_stock(true);
      $p->set_stock_quantity(0);
      $p->set_stock_status('outofstock');
      $p->save();
    }

    $lines[] = $dry_run
      ? "[DRY][PREZERO] {$type} ID={$product_id} -> stock=0"
      : "[OK][PREZERO] {$type} ID={$product_id} -> stock=0";
  }
}
