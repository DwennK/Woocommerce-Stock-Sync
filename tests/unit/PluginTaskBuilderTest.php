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
}
