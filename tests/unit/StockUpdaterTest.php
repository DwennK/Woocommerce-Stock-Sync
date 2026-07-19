<?php

use PHPUnit\Framework\TestCase;

function wc_get_product($product_id) {
  global $wcssd_fake_products;
  return isset($wcssd_fake_products[$product_id]) ? $wcssd_fake_products[$product_id] : false;
}

final class StockUpdaterTest extends TestCase {
  public function test_skips_save_when_target_values_are_unchanged(): void {
    $product = new WCSSD_FakeProduct(42, true, 5, 'instock', '19.90');
    $updater = new WCSSD_StockUpdater();

    $this->assertFalse($updater->apply_if_changed($product, 5, '19.90'));
    $this->assertSame(0, $product->save_count);
  }

  public function test_applies_only_changed_values(): void {
    $product = new WCSSD_FakeProduct(42, false, null, 'outofstock', '10.00');
    $updater = new WCSSD_StockUpdater();

    $this->assertTrue($updater->apply_if_changed($product, 3, '15.00'));
    $this->assertTrue($product->get_manage_stock());
    $this->assertSame(3, $product->get_stock_quantity());
    $this->assertSame('instock', $product->get_stock_status());
    $this->assertSame('15.00', $product->get_regular_price('edit'));
    $this->assertSame(1, $product->save_count);
  }

  public function test_restores_snapshot_values(): void {
    $product = new WCSSD_FakeProduct(42, true, 8, 'instock', '30.00');
    $updater = new WCSSD_StockUpdater();
    $snapshot = $updater->snapshot_product($product);

    $updater->set_stock_and_price($product, 0, '50.00');
    $updater->restore_product($product, $snapshot);

    $this->assertSame(8, $product->get_stock_quantity());
    $this->assertSame('instock', $product->get_stock_status());
    $this->assertSame('30.00', $product->get_regular_price('edit'));
    $this->assertSame(2, $product->save_count);
  }

  public function test_safe_tree_rollback_preserves_later_manual_changes(): void {
    global $wcssd_fake_products;
    $product = new WCSSD_FakeProduct(42, true, 2, 'instock', '30.00');
    $wcssd_fake_products = [42 => $product];
    $updater = new WCSSD_StockUpdater();
    $snapshot = ['nodes' => [[
      'product_id' => 42,
      'manage_stock' => true,
      'qty' => 8,
      'stock_status' => 'instock',
      'regular_price' => '30.00',
    ]]];

    $this->assertSame([42], $updater->restore_product_tree_safely($snapshot));
    $this->assertSame(2, $product->get_stock_quantity());
    $this->assertSame(0, $product->save_count);
  }

  public function test_safe_tree_rollback_restores_zeroed_products(): void {
    global $wcssd_fake_products;
    $product = new WCSSD_FakeProduct(42, true, 0, 'outofstock', '99.00');
    $wcssd_fake_products = [42 => $product];
    $updater = new WCSSD_StockUpdater();
    $snapshot = ['nodes' => [[
      'product_id' => 42,
      'manage_stock' => true,
      'qty' => 8,
      'stock_status' => 'instock',
      'regular_price' => '30.00',
    ]]];

    $this->assertSame([], $updater->restore_product_tree_safely($snapshot));
    $this->assertSame(8, $product->get_stock_quantity());
    $this->assertSame('99.00', $product->get_regular_price('edit'));
    $this->assertSame(1, $product->save_count);
  }
}

final class WCSSD_FakeProduct {
  public int $save_count = 0;
  private int $id;
  private bool $manage_stock;
  private $qty;
  private string $stock_status;
  private string $regular_price;

  public function __construct($id, $manage_stock, $qty, $stock_status, $regular_price) {
    $this->id = (int)$id;
    $this->manage_stock = (bool)$manage_stock;
    $this->qty = $qty;
    $this->stock_status = (string)$stock_status;
    $this->regular_price = (string)$regular_price;
  }

  public function get_id() { return $this->id; }
  public function get_manage_stock() { return $this->manage_stock; }
  public function get_stock_quantity() { return $this->qty; }
  public function get_stock_status() { return $this->stock_status; }
  public function get_regular_price($context = 'view') { return $this->regular_price; }
  public function set_manage_stock($value) { $this->manage_stock = (bool)$value; }
  public function set_stock_quantity($value) { $this->qty = $value === null ? null : (int)$value; }
  public function set_stock_status($value) { $this->stock_status = (string)$value; }
  public function set_regular_price($value) { $this->regular_price = (string)$value; }
  public function save() { $this->save_count++; }
}
