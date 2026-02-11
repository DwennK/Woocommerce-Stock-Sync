# Woocommerce-Stock-Sync
Upload a CSV in wp-admin to update WooCommerce product/variation stock + price. Uses one DB lookup for SKUs, then processes in AJAX chunks with progress bar. Stores job in transient (auto-expiring) and deletes on finish/cancel. No CSV left on disk. Includes optional "Pre-zero stock by category" before sync. 
