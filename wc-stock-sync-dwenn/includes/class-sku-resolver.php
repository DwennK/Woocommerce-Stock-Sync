<?php

if (!defined('ABSPATH')) exit;

class WCSSD_SkuResolver {
  public function resolve_skus_to_posts(array $skus) {
    global $wpdb;

    $skus = array_values(array_unique(array_filter(array_map('trim', $skus))));
    if (!$skus) return [];

    $map = [];
    $chunk_size = 500;
    $chunks = array_chunk($skus, $chunk_size);

    foreach ($chunks as $chunk) {
      $placeholders = implode(',', array_fill(0, count($chunk), '%s'));
      $sql = "
        SELECT pm.meta_value AS sku, p.ID AS post_id, p.post_type, p.post_parent
        FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
        WHERE pm.meta_key = '_sku'
          AND pm.meta_value IN ($placeholders)
          AND p.post_type IN ('product', 'product_variation')
      ";

      // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Dynamic placeholders are generated as %s tokens, then passed through $wpdb->prepare().
      $prepared = (count($chunk) === 1)
        ? $wpdb->prepare($sql, $chunk[0])
        : $wpdb->prepare($sql, ...$chunk);

      $rows = $wpdb->get_results($prepared);
      // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

      if ($rows) {
        foreach ($rows as $r) {
          $sku = (string)$r->sku;
          if (!isset($map[$sku])) {
            $map[$sku] = [
              'post_id'   => (int)$r->post_id,
              'post_type' => (string)$r->post_type,
              'parent_id' => (int)$r->post_parent,
            ];
          }
        }
      }
    }

    return $map;
  }
}
