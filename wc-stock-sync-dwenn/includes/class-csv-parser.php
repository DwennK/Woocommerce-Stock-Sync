<?php

if (!defined('ABSPATH')) exit;

class WCSSD_CsvParser {
  public function normalize_numeric_string($value) {
    $value = trim((string)$value);
    if ($value === '') return '';

    $value = str_replace(["\xC2\xA0", "\xE2\x80\xAF", ' ', "'"], ['', '', '', ''], $value);
    $value = preg_replace('/[^0-9,.\-]/u', '', $value);
    if ($value === '' || $value === '-') return '';

    $last_dot   = strrpos($value, '.');
    $last_comma = strrpos($value, ',');
    $decimal_sep = null;

    if ($last_dot !== false && $last_comma !== false) {
      $decimal_sep = ($last_dot > $last_comma) ? '.' : ',';
    } elseif ($last_dot !== false || $last_comma !== false) {
      $sep = ($last_dot !== false) ? '.' : ',';
      $pos = ($sep === '.') ? $last_dot : $last_comma;
      $digits_after = strlen($value) - $pos - 1;

      if ($digits_after > 0 && $digits_after <= 2) {
        $decimal_sep = $sep;
      }
    }

    if ($decimal_sep !== null) {
      $thousands_sep = ($decimal_sep === '.') ? ',' : '.';
      $value = str_replace($thousands_sep, '', $value);

      if (substr_count($value, $decimal_sep) > 1) {
        $parts = explode($decimal_sep, $value);
        $decimal = array_pop($parts);
        $value = implode('', $parts) . '.' . $decimal;
      } else {
        $value = str_replace($decimal_sep, '.', $value);
      }
    } else {
      $value = str_replace([',', '.'], '', $value);
    }

    return $value;
  }

  public function normalize_price($p) {
    $p = $this->normalize_numeric_string($p);
    if ($p === '' || !is_numeric($p)) return '0.00';
    $f = floatval($p);
    return number_format($f, 2, '.', '');
  }

  public function normalize_stock($s) {
    $s = $this->normalize_numeric_string($s);
    if ($s === '' || !is_numeric($s)) return 0;
    $f = floatval($s);
    return (int)round($f);
  }

  public function normalize_csv_header($header) {
    $header = preg_replace('/^\xEF\xBB\xBF/', '', trim((string)$header));
    if ($header === '') return '';

    $header = strtolower($header);
    return preg_replace('/[^a-z0-9]+/', '', $header);
  }

  public function build_csv_header_map(array $headers, array $column_headers = []) {
    $normalized_headers = [];
    foreach ($headers as $idx => $header) {
      $key = $this->normalize_csv_header($header);
      if ($key !== '' && !isset($normalized_headers[$key])) {
        $normalized_headers[$key] = $idx;
      }
    }

    $aliases = [
      'sku' => ['sku'],
      'available' => ['available', 'stock', 'qty', 'quantity'],
      'price' => ['price', 'regularprice'],
    ];

    foreach ($aliases as $canonical => $keys) {
      if (!empty($column_headers[$canonical])) {
        array_unshift($aliases[$canonical], $this->normalize_csv_header($column_headers[$canonical]));
      }
    }

    $resolved = [];
    foreach ($aliases as $canonical => $keys) {
      foreach ($keys as $key) {
        if (isset($normalized_headers[$key])) {
          $resolved[$canonical] = $normalized_headers[$key];
          break;
        }
      }
    }

    return $resolved;
  }

  public function normalize_delimiter($delimiter) {
    if ($delimiter === 'tab' || $delimiter === "\t") return "\t";
    if ($delimiter === ';') return ';';
    if ($delimiter === ',') return ',';
    return 'auto';
  }

  public function detect_delimiter($tmp) {
    $line = '';
    $handle = fopen($tmp, 'r');
    if ($handle) {
      $line = (string)fgets($handle);
      fclose($handle);
    }

    $candidates = [',', ';', "\t"];
    $best = ',';
    $best_count = 0;

    foreach ($candidates as $candidate) {
      $count = count(str_getcsv($line, $candidate, '"', '\\'));
      if ($count > $best_count) {
        $best = $candidate;
        $best_count = $count;
      }
    }

    return $best;
  }

  public function parse_file($tmp, float $adjust_amount, string $adjust_round, $delimiter = 'auto', array $column_headers = []) {
    $delimiter = $this->normalize_delimiter($delimiter);
    if ($delimiter === 'auto') {
      $delimiter = $this->detect_delimiter($tmp);
    }

    $handle = fopen($tmp, 'r');
    if (!$handle) {
      throw new RuntimeException('Unable to read uploaded CSV.');
    }

    $rows = [];
    $skus = [];

    try {
      $headers = fgetcsv($handle, 0, $delimiter, '"', '\\');
      if ($headers === false) {
        throw new RuntimeException('CSV appears empty.');
      }

      if (!empty($headers[0])) {
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$headers[0]);
      }

      $required = [
        'sku' => 'Sku',
        'available' => 'Available',
        'price' => 'Price',
      ];
      $header_map = $this->build_csv_header_map($headers, $column_headers);
      foreach ($required as $key => $label) {
        if (!isset($header_map[$key])) {
          throw new RuntimeException('Missing required column: ' . $label);
        }
      }

      while (($cols = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
        $sku = isset($cols[$header_map['sku']]) ? trim((string)$cols[$header_map['sku']]) : '';
        if ($sku === '') continue;

        $qty = isset($cols[$header_map['available']]) ? $this->normalize_stock($cols[$header_map['available']]) : 0;
        $price = isset($cols[$header_map['price']]) ? $this->normalize_price($cols[$header_map['price']]) : '0.00';

        $orig_f = floatval($price);
        $adj_f  = $orig_f + $adjust_amount;
        if ($adjust_round === 'integer') {
          $adj_f = round($adj_f);
        }
        $adj_price = number_format($adj_f, 2, '.', '');

        $rows[] = ['sku'=>$sku, 'qty'=>$qty, 'price'=>$adj_price, 'orig_price'=>$price];
        $skus[] = $sku;
      }
    } finally {
      fclose($handle);
    }

    return [
      'rows' => $rows,
      'skus' => $skus,
      'delimiter' => $delimiter,
    ];
  }
}
