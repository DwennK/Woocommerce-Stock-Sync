<?php

use PHPUnit\Framework\TestCase;

final class SkuResolverTest extends TestCase {
  public function test_canonical_sku_is_case_insensitive_and_trimmed(): void {
    $this->assertSame('abc-123', WCSSD_SkuResolver::canonical_sku(' ABC-123 '));
    $this->assertSame('abc-123', WCSSD_SkuResolver::canonical_sku('abc-123'));
  }
}
