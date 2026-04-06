<?php

/*
 * Plugin Name:       Pos Product Sync
 * Description:       Pos Product Sync
 * Version:           1.0.0
 * Author:            Ali Hasan
 */

if (!defined('ABSPATH')) {
    exit;
}

class PosProduct
{
    private const BEARER_TOKEN = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJ0ZXN0LXVzZXIiLCJhdWQiOiJhcGkuZXhhbXBsZS5jb20iLCJpYXQiOjE2OTg1NjAwMDAsImV4cCI6MTY5ODU2MzYwMH0.4JcF5yO3z5uBvFhOQwI8JrR6qJ8tP9x7yQnPjG4kHhA';

    public function __construct()
    {
        add_action('rest_api_init', function () {
            register_rest_route(
                'pos/v1',
                'product',
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'handle_product'],
                    'permission_callback' => [$this, 'check_permission'],
                ]
            );
        });
    }

    public function check_permission($request): bool
    {
        $auth_header = $request->get_header('Authorization');
        if (empty($auth_header)) {
            return false;
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', $auth_header, $matches)) {
            return false;
        }

        return hash_equals(self::BEARER_TOKEN, $matches[1]);
    }

    public function handle_product($request)
    {
        $body = $request->get_json_params();
        $action = $body['eventType'] ?? '';

        switch ($action) {
            case 'create':
                return $this->create_product($body);
            case 'update':
                return $this->update_product($body);
            case 'delete':
                return $this->delete_product($body);
            default:
                return $this->error_response('Invalid action', 400);
        }
    }

    public function create_product($data): WP_REST_Response
    {
        $pos_id = $data['Id'] ?? null;
        if (!$pos_id) {
            return $this->error_response('Product Id required', 422);
        }


        $existing = $this->get_product($pos_id);


        if (!empty($existing)) {
            return $this->error_response('Product already exists', 409);
        }


        $product = new WC_Product_Simple();

        $product->set_name($data['Name'] ?? '');
        $product->set_status('publish');
        $product->set_regular_price($data['OldPrice'] ?? $data['Price'] ?? 0);
        $product->set_price($data['Price'] ?? 0);
        $product->set_sku($data['Code'] ?? '');
        $product->set_description($data['Description'] ?? '');

        // Stock from WarehouseList (sum all warehouses) or fallback to CurrentStock
        $stock_qty = $this->get_total_stock($data);
        $product->set_manage_stock(true);
        $product->set_stock_quantity($stock_qty);
        $product->set_stock_status($stock_qty > 0 ? 'instock' : 'outofstock');

        // Category
        if (!empty($data['CategoryName'])) {
            $cat_id = $this->get_or_create_term($data['CategoryName'], 'product_cat');
            if ($cat_id) {
                $product->set_category_ids([$cat_id]);
            }
        }

        // Brand (stored as a custom attribute or tag — adjust if you have a brand taxonomy)
        if (!empty($data['BrandName'])) {
            $brand_id = $this->get_or_create_term($data['BrandName'], 'product_tag');
            if ($brand_id) {
                $product->set_tag_ids([$brand_id]);
            }
        }

        $product_id = $product->save();

        if (!$product_id) {
            return $this->error_response('Failed to create product', 500);
        }

        // Save POS meta fields
        update_post_meta($product_id, 'product_pos_id', $pos_id);
        update_post_meta($product_id, 'product_pos_type', $data['Type'] ?? '');
        update_post_meta($product_id, 'product_pos_barcode', $data['ProductBarcode'] ?? '');
        update_post_meta($product_id, 'product_pos_unit', $data['UnitName'] ?? '');
        update_post_meta($product_id, 'product_pos_cost_price', $data['CostPrice'] ?? 0);

        // Product image
        if (!empty($data['ImagePath'])) {
            $this->download_product_image($data['ImagePath'], $product_id);
        }

        return $this->success_response('Product created successfully', ['product_id' => $product_id], 201);
    }

    public function update_product($data): WP_REST_Response
    {
        $pos_id = $data['Id'] ?? null;
        if (!$pos_id) {
            return $this->error_response('Product Id required', 422);
        }

        $existing_ids = $this->get_product($pos_id);
        if (empty($existing_ids)) {
            return $this->error_response('Product not found', 404);
        }

        $product = wc_get_product($existing_ids[0]);
        if (!$product) {
            return $this->error_response('Failed to load product', 500);
        }

        $product->set_name($data['Name'] ?? $product->get_name());
        $product->set_regular_price($data['OldPrice'] ?? $data['Price'] ?? $product->get_regular_price());
        $product->set_price($data['Price'] ?? $product->get_price());
        $product->set_description($data['Description'] ?? $product->get_description());

        if (!empty($data['Code'])) {
            $product->set_sku($data['Code']);
        }

        // Stock
        $stock_qty = $this->get_total_stock($data);
        $product->set_stock_quantity($stock_qty);
        $product->set_stock_status($stock_qty > 0 ? 'instock' : 'outofstock');

        // Category
        if (!empty($data['CategoryName'])) {
            $cat_id = $this->get_or_create_term($data['CategoryName'], 'product_cat');
            if ($cat_id) {
                $product->set_category_ids([$cat_id]);
            }
        }

        // Brand
        if (!empty($data['BrandName'])) {
            $brand_id = $this->get_or_create_term($data['BrandName'], 'product_tag');
            if ($brand_id) {
                $product->set_tag_ids([$brand_id]);
            }
        }

        $product_id = $product->save();

        if (!$product_id) {
            return $this->error_response('Failed to update product', 500);
        }

        // Update POS meta
        update_post_meta($product_id, 'product_pos_barcode', $data['ProductBarcode'] ?? '');
        update_post_meta($product_id, 'product_pos_cost_price', $data['CostPrice'] ?? 0);
        update_post_meta($product_id, 'product_pos_unit', $data['UnitName'] ?? '');

        // Update image only if a new one is provided
        if (!empty($data['ImagePath'])) {
            $this->download_product_image($data['ImagePath'], $product_id);
        }

        return $this->success_response('Product updated successfully', ['product_id' => $product_id]);
    }

    public function delete_product($data): WP_REST_Response
    {
        $pos_id = $data['Id'] ?? null;
        if (!$pos_id) {
            return $this->error_response('Product Id required', 422);
        }

        $existing_ids = $this->get_product($pos_id);
        if (empty($existing_ids)) {
            return $this->error_response('Product not found', 404);
        }

        $product = wc_get_product($existing_ids[0]);
        if (!$product) {
            return $this->error_response('Failed to load product', 500);
        }

        // force_delete = true skips trash and permanently deletes
        $product->delete(true);

        return $this->success_response('Product deleted successfully');
    }

    // ─── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Sum stock across all warehouses; fall back to top-level CurrentStock.
     */
    private function get_total_stock(array $data): float
    {
        if (!empty($data['WarehouseList']) && is_array($data['WarehouseList'])) {
            return array_sum(array_column($data['WarehouseList'], 'CurrentStock'));
        }
        return (float)($data['CurrentStock'] ?? 0);
    }

    /**
     * Find or create a term in the given taxonomy and return its ID.
     */
    private function get_or_create_term(string $name, string $taxonomy): ?int
    {
        $term = get_term_by('name', $name, $taxonomy);
        if ($term) {
            return $term->term_id;
        }

        $result = wp_insert_term($name, $taxonomy);
        if (is_wp_error($result)) {
            return null;
        }

        return $result['term_id'];
    }

    public function download_product_image($image_url, $product_id)
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url($image_url);

        error_log('temp file: ' . print_r($tmp, true));
        if (is_wp_error($tmp)) {
            return;
        }

        $file_array = [
            'name' => basename($image_url),
            'tmp_name' => $tmp,
        ];


        error_log('File Arr: ' . print_r($file_array, true));

        $attachment_id = media_handle_sideload($file_array, $product_id);
        if (!is_wp_error($attachment_id)) {
            set_post_thumbnail($product_id, $attachment_id);
        }

        error_log('Attachment ID: ' . print_r($attachment_id, true));


        if (file_exists($tmp)) {
            @unlink($tmp);
        }
    }

    public function get_product($pos_id)
    {
        global $wpdb;

        $product_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT pm.post_id
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key   = 'product_pos_id'
                   AND pm.meta_value = %s
                   AND p.post_type   = 'product'
                   AND p.post_status != 'trash'",
                (string)$pos_id
            )
        );

        return !empty($product_ids) ? $product_ids : [];
    }


    private function success_response(string $message, array $data = [], int $status = 200): WP_REST_Response
    {
        return new WP_REST_Response([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    private function error_response(string $message, int $status = 400): WP_REST_Response
    {
        return new WP_REST_Response([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}

add_action('plugins_loaded', function () {
    new PosProduct();
});
