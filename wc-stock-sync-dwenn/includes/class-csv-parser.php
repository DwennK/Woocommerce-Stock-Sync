<?php

if (!defined('ABSPATH')) exit;

class WCSSD_CsvParser {
  public function normalize_numeric_string($value) {
    $value = trim((string)$value);
    if ($value === '') return null;

    $value = str_replace(["\xC2\xA0", "\xE2\x80\xAF", ' ', "'"], ['', '', '', ''], $value);
    if (!preg_match('/^-?[0-9][0-9,.]*$/', $value)) return null;
    if (!$this->is_valid_numeric_format($value)) return null;

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
    if ($p === null || !is_numeric($p)) return null;
    $f = floatval($p);
    if (!is_finite($f) || $f < 0) return null;
    return number_format($f, 2, '.', '');
  }

  public function normalize_stock($s) {
    $s = $this->normalize_numeric_string($s);
    if ($s === null || !is_numeric($s)) return null;
    $f = floatval($s);
    if (!is_finite($f) || $f < 0 || floor($f) !== $f) return null;
    return (int)$f;
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
    $errors = [];
    $warnings = [];
    $seen_skus = [];

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

      $line_number = 1;
      while (($cols = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
        $line_number++;
        $sku = isset($cols[$header_map['sku']]) ? trim((string)$cols[$header_map['sku']]) : '';
        $raw_stock = isset($cols[$header_map['available']]) ? trim((string)$cols[$header_map['available']]) : '';
        $raw_price = isset($cols[$header_map['price']]) ? trim((string)$cols[$header_map['price']]) : '';

        if ($sku === '') {
          $errors[] = $this->validation_error($line_number, 'sku', $sku, 'SKU is required.');
          continue;
        }

        $qty = $this->normalize_stock($raw_stock);
        $price = $this->normalize_price($raw_price);
        if ($qty === null) {
          $errors[] = $this->validation_error($line_number, 'available', $raw_stock, 'Stock must be a non-negative integer.', $sku);
        }
        if ($price === null) {
          $errors[] = $this->validation_error($line_number, 'price', $raw_price, 'Price must be a non-negative number.', $sku);
        }
        if ($qty === null || $price === null) {
          continue;
        }

        $orig_f = floatval($price);
        $adj_f  = $orig_f + $adjust_amount;
        if ($adjust_round === 'integer') {
          $adj_f = round($adj_f);
        }
        if (!is_finite($adj_f) || $adj_f < 0) {
          $errors[] = $this->validation_error($line_number, 'price', $raw_price, 'Adjusted price must not be negative.', $sku);
          continue;
        }
        $adj_price = number_format($adj_f, 2, '.', '');

        $sku_key = strtolower($sku);
        if (isset($seen_skus[$sku_key])) {
          $errors[] = $this->validation_error(
            $line_number,
            'sku',
            $sku,
            'Duplicate SKU; first seen on line ' . $seen_skus[$sku_key] . '.'
          );
          continue;
        }
        $seen_skus[$sku_key] = $line_number;

        $rows[] = [
          'line' => $line_number,
          'sku' => $sku,
          'qty' => $qty,
          'price' => $adj_price,
          'orig_price' => $price,
          'raw_stock' => $raw_stock,
          'raw_price' => $raw_price,
        ];
        $skus[] = $sku;
      }
    } finally {
      fclose($handle);
    }

    if (!$rows && !$errors) {
      $errors[] = $this->validation_error(1, 'file', '', 'CSV does not contain any data rows.');
    }

    return [
      'rows' => $rows,
      'skus' => $skus,
      'delimiter' => $delimiter,
      'errors' => $errors,
      'warnings' => $warnings,
    ];
  }

  private function validation_error($line, $field, $value, $message, $sku = '') {
    return [
      'line' => (int)$line,
      'field' => (string)$field,
      'value' => (string)$value,
      'sku' => (string)$sku,
      'message' => (string)$message,
    ];
  }

  private function is_valid_numeric_format($value) {
    $unsigned = ltrim((string)$value, '-');
    $dot_count = substr_count($unsigned, '.');
    $comma_count = substr_count($unsigned, ',');

    if ($dot_count === 0 && $comma_count === 0) return ctype_digit($unsigned);

    if ($dot_count > 0 && $comma_count > 0) {
      $last_dot = strrpos($unsigned, '.');
      $last_comma = strrpos($unsigned, ',');
      $decimal_sep = $last_dot > $last_comma ? '.' : ',';
      $thousands_sep = $decimal_sep === '.' ? ',' : '.';
      if (substr_count($unsigned, $decimal_sep) !== 1) return false;

      $parts = explode($decimal_sep, $unsigned);
      if (count($parts) !== 2 || !preg_match('/^[0-9]{1,2}$/', $parts[1])) return false;
      $integer_pattern = '/^[0-9]{1,3}(?:' . preg_quote($thousands_sep, '/') . '[0-9]{3})*$/';
      return (bool)preg_match($integer_pattern, $parts[0]);
    }

    $sep = $dot_count ? '.' : ',';
    $count = $dot_count ?: $comma_count;
    if ($count > 1) {
      return (bool)preg_match('/^[0-9]{1,3}(?:' . preg_quote($sep, '/') . '[0-9]{3})+$/', $unsigned);
    }

    $parts = explode($sep, $unsigned);
    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') return false;
    $fraction_length = strlen($parts[1]);
    if ($fraction_length <= 2) return ctype_digit($parts[0]) && ctype_digit($parts[1]);
    return $fraction_length === 3 && strlen($parts[0]) <= 3 && ctype_digit($parts[0]) && ctype_digit($parts[1]);
  }
}
