<?php

if (!defined('ABSPATH')) exit;

class WCSSD_StockUpdater {
  public function snapshot_product($product) {
    return [
      'product_id' => (int)$product->get_id(),
      'manage_stock' => (bool)$product->get_manage_stock(),
      'qty' => $product->get_stock_quantity() === null ? null : (int)$product->get_stock_quantity(),
      'stock_status' => (string)$product->get_stock_status(),
      'regular_price' => (string)$product->get_regular_price('edit'),
    ];
  }

  public function product_needs_update($product, int $qty, string $price) {
    return !$this->product_matches_target($product, $qty, $price);
  }

  public function product_matches_target($product, int $qty, string $price) {
    $snapshot = $this->snapshot_product($product);
    return !empty($snapshot['manage_stock'])
      && $snapshot['qty'] === $qty
      && $snapshot['stock_status'] === ($qty > 0 ? 'instock' : 'outofstock')
      && $this->prices_equal($snapshot['regular_price'], $price);
  }

  public function product_matches_snapshot($product, array $expected) {
    $current = $this->snapshot_product($product);
    return (bool)$current['manage_stock'] === !empty($expected['manage_stock'])
      && $current['qty'] === (array_key_exists('qty', $expected) ? $expected['qty'] : null)
      && $current['stock_status'] === (string)$expected['stock_status']
      && $this->prices_equal($current['regular_price'], isset($expected['regular_price']) ? $expected['regular_price'] : '');
  }

  public function apply_if_changed($product, int $qty, string $price) {
    if (!$this->product_needs_update($product, $qty, $price)) {
      return false;
    }

    $this->set_stock_and_price($product, $qty, $price);
    return true;
  }

  public function set_stock_and_price($product, int $qty, string $price) {
    $product->set_manage_stock(true);
    $product->set_stock_quantity($qty);
    $product->set_stock_status($qty > 0 ? 'instock' : 'outofstock');
    $product->set_regular_price($price);
    $product->save();
  }

  public function restore_product($product, array $snapshot) {
    $this->restore_stock_fields($product, $snapshot);
    if (array_key_exists('regular_price', $snapshot)) {
      $product->set_regular_price((string)$snapshot['regular_price']);
    }
    $product->save();
  }

  public function restore_stock($product, array $snapshot) {
    $this->restore_stock_fields($product, $snapshot);
    $product->save();
  }

  private function restore_stock_fields($product, array $snapshot) {
    $product->set_manage_stock(!empty($snapshot['manage_stock']));
    $product->set_stock_quantity(array_key_exists('qty', $snapshot) ? $snapshot['qty'] : null);
    if (isset($snapshot['stock_status'])) {
      $product->set_stock_status((string)$snapshot['stock_status']);
    }
  }

  public function snapshot_product_tree($product_id) {
    $product = wc_get_product($product_id);
    if (!$product) return [];

    $nodes = [$this->snapshot_product($product)];
    if ($product->get_type() === 'variable') {
      foreach ($product->get_children() as $child_id) {
        $child = wc_get_product($child_id);
        if ($child) $nodes[] = $this->snapshot_product($child);
      }
    }
    return ['nodes' => $nodes];
  }

  public function product_tree_needs_zero(array $snapshot) {
    foreach (!empty($snapshot['nodes']) ? $snapshot['nodes'] : [] as $node) {
      if (!empty($node['manage_stock']) && (int)$node['qty'] !== 0) return true;
      if (!empty($node['stock_status']) && $node['stock_status'] !== 'outofstock') return true;
    }
    return false;
  }

  public function restore_product_tree(array $snapshot) {
    $nodes = !empty($snapshot['nodes']) && is_array($snapshot['nodes']) ? array_reverse($snapshot['nodes']) : [];
    foreach ($nodes as $node) {
      if (empty($node['product_id'])) continue;
      $product = wc_get_product((int)$node['product_id']);
      if ($product) $this->restore_stock($product, $node);
    }
  }

  public function restore_product_tree_safely(array $snapshot) {
    $conflicts = [];
    $nodes = !empty($snapshot['nodes']) && is_array($snapshot['nodes']) ? array_reverse($snapshot['nodes']) : [];
    foreach ($nodes as $node) {
      if (empty($node['product_id'])) continue;
      $product = wc_get_product((int)$node['product_id']);
      if (!$product) {
        $conflicts[] = (int)$node['product_id'];
        continue;
      }
      if ($this->product_matches_stock_snapshot($product, $node)) continue;
      $current = $this->snapshot_product($product);
      $is_zeroed = $current['stock_status'] === 'outofstock'
        && (empty($current['manage_stock']) || $current['qty'] === 0 || $current['qty'] === null);
      if (!$is_zeroed) {
        $conflicts[] = (int)$node['product_id'];
        continue;
      }
      $this->restore_stock($product, $node);
    }
    return $conflicts;
  }

  private function product_matches_stock_snapshot($product, array $expected) {
    $current = $this->snapshot_product($product);
    return (bool)$current['manage_stock'] === !empty($expected['manage_stock'])
      && $current['qty'] === (array_key_exists('qty', $expected) ? $expected['qty'] : null)
      && $current['stock_status'] === (string)$expected['stock_status'];
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

  private function prices_equal($left, $right) {
    if ($left === '' && $right === '') return true;
    if (!is_numeric($left) || !is_numeric($right)) return (string)$left === (string)$right;
    return abs((float)$left - (float)$right) < 0.00001;
  }
}
