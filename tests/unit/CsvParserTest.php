<?php

use PHPUnit\Framework\TestCase;

final class CsvParserTest extends TestCase {
  private WCSSD_CsvParser $parser;

  protected function setUp(): void {
    $this->parser = new WCSSD_CsvParser();
  }

  public function test_normalizes_common_supplier_number_formats(): void {
    $this->assertSame('1299.00', $this->parser->normalize_price('1,299.00'));
    $this->assertSame('1299.00', $this->parser->normalize_price('1.299,00'));
    $this->assertSame('1299.00', $this->parser->normalize_price("1'299.00"));
  }

  public function test_normalizes_empty_values(): void {
    $this->assertSame('0.00', $this->parser->normalize_price(''));
    $this->assertSame(0, $this->parser->normalize_stock(''));
  }

  public function test_accepts_header_aliases(): void {
    $result = $this->parseCsvString("Make,Qty,Regular Price,SKU\nApple,7,299.95,ABC-123\n");

    $this->assertSame('ABC-123', $result['rows'][0]['sku']);
    $this->assertSame(7, $result['rows'][0]['qty']);
    $this->assertSame('299.95', $result['rows'][0]['price']);
  }

  public function test_auto_detects_semicolon_delimited_csv(): void {
    $result = $this->parseCsvString("Sku;Available;Price\nSEMI-1;3;49.90\n");

    $this->assertSame(';', $result['delimiter']);
    $this->assertSame('SEMI-1', $result['rows'][0]['sku']);
    $this->assertSame(3, $result['rows'][0]['qty']);
    $this->assertSame('49.90', $result['rows'][0]['price']);
  }

  public function test_auto_detects_tab_delimited_csv(): void {
    $result = $this->parseCsvString("Sku\tAvailable\tPrice\nTAB-1\t6\t19.95\n");

    $this->assertSame("\t", $result['delimiter']);
    $this->assertSame('TAB-1', $result['rows'][0]['sku']);
    $this->assertSame(6, $result['rows'][0]['qty']);
    $this->assertSame('19.95', $result['rows'][0]['price']);
  }

  public function test_accepts_custom_profile_column_names(): void {
    $result = $this->parseCsvString(
      "Article No,On Hand,Dealer Cost\nSKU-9,11,44.10\n",
      0.0,
      'none',
      'auto',
      ['sku' => 'Article No', 'available' => 'On Hand', 'price' => 'Dealer Cost']
    );

    $this->assertSame('SKU-9', $result['rows'][0]['sku']);
    $this->assertSame(11, $result['rows'][0]['qty']);
    $this->assertSame('44.10', $result['rows'][0]['price']);
  }

  public function test_allows_negative_adjusted_prices(): void {
    $result = $this->parseCsvString("Sku,Available,Price\nNEG-1,1,25.00\n", -30.0);

    $this->assertSame('-5.00', $result['rows'][0]['price']);
  }

  private function parseCsvString(
    string $csv,
    float $adjustAmount = 0.0,
    string $adjustRound = 'none',
    string $delimiter = 'auto',
    array $columnHeaders = []
  ): array {
    $file = tempnam(sys_get_temp_dir(), 'wcssd_csv_');
    file_put_contents($file, $csv);

    try {
      return $this->parser->parse_file($file, $adjustAmount, $adjustRound, $delimiter, $columnHeaders);
    } finally {
      unlink($file);
    }
  }
}
