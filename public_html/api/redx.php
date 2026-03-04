<?php
/**
 * RedX Courier API Integration
 * Base URL: https://openapi.redx.com.bd/v1.0.0-beta
 * 
 * Endpoints:
 *   GET   /parcel/track/<tracking_id>           - Track parcel
 *   GET   /parcel/info/<tracking_id>            - Get parcel details
 *   POST  /parcel                               - Create parcel
 *   PATCH /parcels                              - Update/cancel parcel
 *   GET   /areas                                - Get all areas
 *   GET   /areas?post_code=<code>               - Areas by postal code
 *   GET   /areas?district_name=<name>           - Areas by district
 *   POST  /pickup/store                         - Create pickup store
 *   GET   /pickup/stores                        - List pickup stores
 *   GET   /pickup/store/info/<id>               - Pickup store details
 *   GET   /charge/charge_calculator             - Calculate delivery charge
 */
require_once __DIR__ . '/courier-rate-limiter.php';

class RedXAPI {
    private $baseUrl;
    private $token;
    private $db;

    public function __construct($token = null) {
        $this->db = Database::getInstance();
        $this->token   = $token ?: $this->setting('redx_api_token');
        $env = $this->setting('redx_environment') ?: 'production';
        $this->baseUrl = ($env === 'sandbox')
            ? 'https://sandbox.redx.com.bd/v1.0.0-beta'
            : 'https://openapi.redx.com.bd/v1.0.0-beta';
    }

    public function setting($key) {
        try {
            $row = $this->db->fetch("SELECT setting_value FROM site_settings WHERE setting_key = ?", [$key]);
            return $row ? ($row['setting_value'] ?? '') : '';
        } catch (\Exception $e) { return ''; }
    }

    public function saveSetting($key, $value) {
        try {
            $exists = $this->db->fetch("SELECT id FROM site_settings WHERE setting_key = ?", [$key]);
            if ($exists) {
                $this->db->update('site_settings', ['setting_value' => $value], 'setting_key = ?', [$key]);
            } else {
                $this->db->insert('site_settings', [
                    'setting_key'   => $key,
                    'setting_value' => $value,
                    'setting_type'  => 'text',
                    'setting_group' => 'redx',
                    'label'         => ucwords(str_replace(['redx_', '_'], ['', ' '], $key)),
                ]);
            }
        } catch (\Exception $e) {}
    }

    public function isConfigured() {
        return !empty($this->token);
    }

    // ══════════════════════════════════════════
    // PARCEL OPERATIONS
    // ══════════════════════════════════════════

    /**
     * Track a parcel
     * @param string $trackingId RedX tracking ID
     * @return array Tracking history
     */
    public function trackParcel($trackingId) {
        return $this->http('GET', '/parcel/track/' . urlencode($trackingId));
    }

    /**
     * Get parcel details
     * @param string $trackingId RedX tracking ID
     * @return array Parcel info
     */
    public function getParcelInfo($trackingId) {
        return $this->http('GET', '/parcel/info/' . urlencode($trackingId));
    }

    /**
     * Create a parcel on RedX
     * @param array $data Parcel data
     * @return array Response with tracking_id
     */
    public function createParcel(array $data) {
        return $this->http('POST', '/parcel', $data);
    }

    /**
     * Cancel a parcel
     * @param string $trackingId RedX tracking ID
     * @param string $reason Cancellation reason
     * @return array Response
     */
    public function cancelParcel($trackingId, $reason = '') {
        return $this->http('PATCH', '/parcels', [
            'entity_type'    => 'parcel-tracking-id',
            'entity_id'      => $trackingId,
            'update_details' => [
                'property_name' => 'status',
                'new_value'     => 'cancelled',
                'reason'        => $reason ?: 'Cancelled by merchant',
            ],
        ]);
    }

    // ══════════════════════════════════════════
    // AREA OPERATIONS
    // ══════════════════════════════════════════

    /**
     * Get all delivery areas (cached 24h — static data)
     */
    public function getAreas() {
        return courierCacheStatic('redx_areas', function() {
            return $this->http('GET', '/areas');
        }, 86400);
    }

    /**
     * Get areas by postal code (cached 24h)
     */
    public function getAreasByPostCode($postCode) {
        return courierCacheStatic("redx_areas_pc_{$postCode}", function() use ($postCode) {
            return $this->http('GET', '/areas?post_code=' . intval($postCode));
        }, 86400);
    }

    /**
     * Get areas by district name (cached 24h)
     */
    public function getAreasByDistrict($district) {
        return courierCacheStatic("redx_areas_d_" . md5($district), function() use ($district) {
            return $this->http('GET', '/areas?district_name=' . urlencode($district));
        }, 86400);
    }

    // ══════════════════════════════════════════
    // PICKUP STORE OPERATIONS
    // ══════════════════════════════════════════

    /**
     * Create a pickup store
     */
    public function createPickupStore(array $data) {
        return $this->http('POST', '/pickup/store', $data);
    }

    /**
     * List all pickup stores
     */
    public function getPickupStores() {
        return $this->http('GET', '/pickup/stores');
    }

    /**
     * Get pickup store details
     */
    public function getPickupStoreInfo($storeId) {
        return $this->http('GET', '/pickup/store/info/' . intval($storeId));
    }

    // ══════════════════════════════════════════
    // CHARGE CALCULATOR
    // ══════════════════════════════════════════

    /**
     * Calculate delivery charge
     * @param int $deliveryAreaId
     * @param int $pickupAreaId
     * @param float $codAmount
     * @param int $weight Weight in grams
     * @return array {deliveryCharge, codCharge}
     */
    public function calculateCharge($deliveryAreaId, $pickupAreaId, $codAmount, $weight) {
        $params = http_build_query([
            'delivery_area_id'      => intval($deliveryAreaId),
            'pickup_area_id'        => intval($pickupAreaId),
            'cash_collection_amount' => floatval($codAmount),
            'weight'                => intval($weight),
        ]);
        return $this->http('GET', '/charge/charge_calculator?' . $params);
    }

    // ══════════════════════════════════════════
    // UPLOAD ORDER FROM OUR DATABASE
    // ══════════════════════════════════════════

    /**
     * Upload a single order to RedX
     * @param int $orderId Our order ID
     * @param array $overrides Optional overrides
     * @return array Result with success/error
     */
    public function uploadOrder(int $orderId, array $overrides = []) {
        $order = $this->db->fetch("SELECT * FROM orders WHERE id = ?", [$orderId]);
        if (!$order) throw new \Exception("Order #{$orderId} not found");

        // Build instruction/note
        $sendProductNames = $this->setting('redx_send_product_names') !== '0';
        $instruction = $overrides['instruction'] ?? ($order['notes'] ?? '');

        // Append default note
        $defaultNote = $this->setting('redx_default_note');
        if ($defaultNote && empty($overrides['instruction'])) {
            $instruction = $instruction ? $instruction . ' | ' . $defaultNote : $defaultNote;
        }

        // Append product names if enabled
        if ($sendProductNames && empty($overrides['instruction'])) {
            try {
                $items = $this->db->fetchAll("SELECT product_name, quantity FROM order_items WHERE order_id = ?", [$orderId]);
                $itemDesc = [];
                foreach ($items as $item) {
                    $itemDesc[] = $item['product_name'] . ($item['quantity'] > 1 ? ' x' . $item['quantity'] : '');
                }
                if ($itemDesc) {
                    $instruction = implode(', ', $itemDesc) . ($instruction ? ' | ' . $instruction : '');
                }
            } catch (\Throwable $e) {}
        }

        $codAmount = $overrides['cod_amount'] ?? (($order['payment_method'] === 'cod') ? floatval($order['total']) : 0);
        $pickupStoreId = intval($overrides['pickup_store_id'] ?? $this->setting('redx_default_pickup_store_id') ?? 0);

        // Determine delivery area - try to match from RedX areas
        $deliveryArea   = $overrides['delivery_area'] ?? ($order['delivery_area_name'] ?? $order['customer_district'] ?? '');
        $deliveryAreaId = intval($overrides['delivery_area_id'] ?? 0);

        // If no explicit area_id, try to find it from our cached areas or the customer address
        if (!$deliveryAreaId && $deliveryArea) {
            $deliveryAreaId = $this->resolveAreaId($deliveryArea, $order['customer_postal_code'] ?? '');
        }

        // Estimate weight (default 500g if not specified)
        $weight = intval($overrides['weight'] ?? $this->setting('redx_default_weight') ?? 500);

        $parcelData = [
            'customer_name'          => $order['customer_name'],
            'customer_phone'         => $order['customer_phone'],
            'customer_address'       => $order['customer_address'],
            'merchant_invoice_id'    => $order['order_number'],
            'cash_collection_amount' => strval($codAmount),
            'parcel_weight'          => $weight,
            'instruction'            => $instruction,
            'value'                  => floatval($order['subtotal'] ?? $order['total'] ?? 0),
        ];

        // Only add delivery_area fields if valid (RedX rejects 0 or empty)
        if (!empty($deliveryArea)) $parcelData['delivery_area'] = $deliveryArea;
        if ($deliveryAreaId > 0) $parcelData['delivery_area_id'] = $deliveryAreaId;

        if ($pickupStoreId) {
            $parcelData['pickup_store_id'] = $pickupStoreId;
        }

        $resp = $this->createParcel($parcelData);

        // Parse response — RedX returns {"tracking_id": "..."}
        $trackingId = $resp['tracking_id'] ?? '';

        if (!empty($trackingId)) {
            // Update order
            $this->db->update('orders', [
                'courier_name'           => 'RedX',
                'courier_tracking_id'    => $trackingId,
                'courier_consignment_id' => $trackingId,
                'courier_status'         => 'pickup-pending',
                'courier_uploaded_at'    => date('Y-m-d H:i:s'),
                'order_status'           => 'ready_to_ship',
                'shipping_method'        => 'RedX',
                'updated_at'             => date('Y-m-d H:i:s'),
            ], 'id = ?', [$orderId]);

            // Log upload
            try {
                $this->db->insert('courier_uploads', [
                    'order_id'         => $orderId,
                    'courier_provider' => 'redx',
                    'consignment_id'   => $trackingId,
                    'tracking_id'      => $trackingId,
                    'status'           => 'uploaded',
                    'response_data'    => json_encode($resp),
                ]);
            } catch (\Throwable $e) {}

            // Log status change
            try {
                $this->db->insert('order_status_history', [
                    'order_id' => $orderId,
                    'status'   => 'ready_to_ship',
                    'note'     => "Uploaded to RedX. Tracking: {$trackingId}",
                ]);
            } catch (\Throwable $e) {}

            return [
                'success'     => true,
                'tracking_id' => $trackingId,
                'message'     => 'Uploaded to RedX successfully',
            ];
        }

        $errMsg = $resp['message'] ?? $resp['error'] ?? 'Upload failed';
        // Include validation errors if present
        if (!empty($resp['errors'])) {
            $details = [];
            foreach ($resp['errors'] as $field => $msgs) {
                if (is_array($msgs)) $details[] = $field . ': ' . implode(', ', $msgs);
                else $details[] = $field . ': ' . $msgs;
            }
            if ($details) $errMsg .= ' — ' . implode('; ', $details);
        }
        return [
            'success' => false,
            'message' => $errMsg,
            'raw'     => $resp,
        ];
    }

    /**
     * Bulk upload multiple orders
     */
    public function bulkUploadOrders(array $orderIds) {
        $results = ['success' => 0, 'failed' => 0, 'errors' => [], 'uploaded' => []];

        foreach ($orderIds as $oid) {
            try {
                $r = $this->uploadOrder(intval($oid));
                if ($r['success'] ?? false) {
                    $results['success']++;
                    $results['uploaded'][] = ['order_id' => $oid, 'tracking_id' => $r['tracking_id'] ?? ''];
                } else {
                    $results['failed']++;
                    $results['errors'][] = "Order #{$oid}: " . ($r['message'] ?? 'Failed');
                }
            } catch (\Throwable $e) {
                $results['failed']++;
                $results['errors'][] = "Order #{$oid}: " . $e->getMessage();
            }
            // Throttle to avoid rate limits
            usleep(200000); // 200ms
        }

        return $results;
    }

    /**
     * Try to resolve a RedX area ID from area name or postal code
     */
    private function resolveAreaId($areaName, $postalCode = '') {
        try {
            // Try postal code first
            if ($postalCode) {
                $resp = $this->getAreasByPostCode($postalCode);
                $areas = $resp['areas'] ?? [];
                if (!empty($areas)) {
                    // Try exact name match first
                    foreach ($areas as $a) {
                        if (stripos($a['name'] ?? '', $areaName) !== false) {
                            return intval($a['id']);
                        }
                    }
                    // Return first match
                    return intval($areas[0]['id'] ?? 0);
                }
            }

            // Try district name
            if ($areaName) {
                $resp = $this->getAreasByDistrict($areaName);
                $areas = $resp['areas'] ?? [];
                if (!empty($areas)) {
                    return intval($areas[0]['id'] ?? 0);
                }
            }
        } catch (\Throwable $e) {}

        return 0;
    }

    // ══════════════════════════════════════════
    // HTTP HELPER
    // ══════════════════════════════════════════

    private function http($method, $path, $data = []) {
        if (!$this->isConfigured()) {
            throw new \Exception('RedX API not configured — please enter your API token in Courier Settings');
        }

        // Rate limit check (80 req/min for RedX)
        if (!courierRateCheck('redx', courierRateLimit('redx'))) {
            throw new \Exception('RedX rate limit reached (80/min) — try again shortly');
        }

        $url = $this->baseUrl . $path;
        $headers = [
            'API-ACCESS-TOKEN: Bearer ' . $this->token,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'PATCH') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        // Execute with exponential backoff retry on 429/503
        $result = courierCurlExec($ch, 'redx', "{$method} {$path}", 5);
        curl_close($ch);

        if ($result['error']) throw new \Exception("RedX API error: {$result['error']}");
        if ($result['http_code'] >= 500) throw new \Exception("RedX server error (HTTP {$result['http_code']})");

        $decoded = json_decode($result['response'], true);
        if (!is_array($decoded)) {
            throw new \Exception("Invalid response from RedX (HTTP {$result['http_code']}): " . substr($result['response'] ?? '', 0, 200));
        }

        return $decoded;
    }
}
