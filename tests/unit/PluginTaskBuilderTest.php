<?php

use PHPUnit\Framework\TestCase;

final class PluginTaskBuilderTest extends TestCase {
  public function test_last_csv_row_wins_for_duplicate_skus(): void {
    $rows = [
      ['sku' => 'DUP-1', 'qty' => 2, 'price' => '10.00', 'orig_price' => '10.00'],
      ['sku' => 'OTHER-1', 'qty' => 5, 'price' => '50.00', 'orig_price' => '50.00'],
      ['sku' => 'DUP-1', 'qty' => 8, 'price' => '80.00', 'orig_price' => '80.00'],
    ];
    $skuToPost = [
      'DUP-1' => ['post_id' => 101, 'post_type' => 'product', 'parent_id' => 0],
      'OTHER-1' => ['post_id' => 202, 'post_type' => 'product_variation', 'parent_id' => 201],
    ];

    $taskPlan = WCSSD_Plugin::build_tasks_from_rows($rows, $skuToPost);

    $this->assertCount(2, $taskPlan['tasks']);
    $this->assertSame(8, $taskPlan['tasks'][0]['qty']);
    $this->assertSame('80.00', $taskPlan['tasks'][0]['price']);
    $this->assertSame(0, $taskPlan['missing']);
  }

  public function test_missing_skus_are_counted_without_creating_tasks(): void {
    $rows = [
      ['sku' => 'KNOWN-1', 'qty' => 2, 'price' => '10.00', 'orig_price' => '10.00'],
      ['sku' => 'MISSING-1', 'qty' => 5, 'price' => '50.00', 'orig_price' => '50.00'],
    ];
    $skuToPost = [
      'KNOWN-1' => ['post_id' => 101, 'post_type' => 'product', 'parent_id' => 0],
    ];

    $taskPlan = WCSSD_Plugin::build_tasks_from_rows($rows, $skuToPost);

    $this->assertCount(1, $taskPlan['tasks']);
    $this->assertSame('KNOWN-1', $taskPlan['tasks'][0]['sku']);
    $this->assertSame(1, $taskPlan['missing']);
  }

  public function test_only_duplicate_sku_validation_errors_are_conflicts(): void {
    $duplicate = ['message' => 'Duplicate SKU; first seen on line 2.'];
    $invalidStock = ['message' => 'Stock must be a non-negative integer.'];

    $this->assertSame('conflict', WCSSD_Plugin::validation_error_status($duplicate));
    $this->assertSame('invalid', WCSSD_Plugin::validation_error_status($invalidStock));
  }

  public function test_rollback_completion_reports_partial_failures(): void {
    $this->assertSame('rolled_back', WCSSD_Plugin::rollback_completion_status(0));
    $this->assertSame('rollback_partial', WCSSD_Plugin::rollback_completion_status(2));
  }

  public function test_csv_export_neutralizes_formula_cells(): void {
    $this->assertSame("'=HYPERLINK(\"https://example.test\")", WCSSD_Plugin::csv_export_cell('=HYPERLINK("https://example.test")'));
    $this->assertSame('SAFE-SKU', WCSSD_Plugin::csv_export_cell('SAFE-SKU'));
  }
}
