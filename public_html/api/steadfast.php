<?php
/**
 * Steadfast Courier API Integration - Full Feature
 * Base URL: https://portal.packzy.com/api/v1
 * 
 * Endpoints:
 *   POST /create_order              - Place single order
 *   POST /create_order/bulk-order   - Bulk order (max 500)
 *   GET  /status_by_cid/{id}        - Status by consignment ID
 *   GET  /status_by_invoice/{inv}   - Status by invoice
 *   GET  /status_by_trackingcode/{c} - Status by tracking code
 *   GET  /get_balance               - Current balance
 *   POST /return-request            - Create return request
 *   GET  /return-request/{id}       - View return request
 *   GET  /return-requests           - List return requests
 *   GET  /payments                  - Get payments list
 *   GET  /payments/{id}             - Get single payment with consignments
 *   GET  /policestations            - Get police stations
 */
require_once __DIR__ . '/courier-rate-limiter.php';

class SteadfastAPI {
    private $baseUrl = 'https://portal.packzy.com/api/v1';
    private $apiKey;
    private $secretKey;
    private $db;

    public function __construct($apiKey = null, $secretKey = null) {
        $this->db = Database::getInstance();
        $this->apiKey    = trim($apiKey ?: $this->setting('steadfast_api_key'));
        $this->secretKey = trim($secretKey ?: $this->setting('steadfast_secret_key'));
    }

    public function setting($key) {
        try {
            $row = $this->db->fetch("SELECT setting_value FROM site_settings WHERE setting_key = ?", [$key]);
            return $row ? $row['setting_value'] : '';
        } catch (\Exception $e) { return ''; }
    }

    public function saveSetting($key, $value) {
        try {
            $exists = $this->db->fetch("SELECT id FROM site_settings WHERE setting_key = ?", [$key]);
            if ($exists) { $this->db->update('site_settings', ['setting_value' => $value], 'setting_key = ?', [$key]); }
            else { $this->db->insert('site_settings', ['setting_key'=>$key,'setting_value'=>$value,'setting_type'=>'text','setting_group'=>'steadfast','label'=>ucwords(str_replace(['steadfast_','_'],['','  '],$key))]); }
        } catch (\Exception $e) {}
    }

    public function isConfigured() { return !empty($this->apiKey) && !empty($this->secretKey); }

    // ── Core Order Operations ──
    
    /**
     * Place a single order
     * @param array $data [invoice, recipient_name, recipient_phone, recipient_address, cod_amount, note]
     */
    public function createOrder(array $data) {
        // Append default shipping note if configured and note is empty
        if (empty($data['note'])) {
            $defaultNote = $this->setting('steadfast_default_note');
            if ($defaultNote) $data['note'] = $defaultNote;
        }
        return $this->http('POST', '/create_order', $data);
    }

    /**
     * Bulk create orders (max 500)
     * @param array $orders Array of order data arrays
     */
    public function bulkCreateOrder(array $orders) {
        // Append default note to each order
        $defaultNote = $this->setting('steadfast_default_note');
        if ($defaultNote) {
            foreach ($orders as &$o) {
                if (empty($o['note'])) $o['note'] = $defaultNote;
            }
        }
        return $this->http('POST', '/create_order/bulk-order', ['orders' => $orders]);
    }

    // ── Status Checking ──
    
    public function getStatusByCid($consignmentId) { return $this->http('GET', '/status_by_cid/' . urlencode($consignmentId)); }
    public function getStatusByInvoice($invoice) { return $this->http('GET', '/status_by_invoice/' . urlencode($invoice)); }
    public function getStatusByTrackingCode($code) { return $this->http('GET', '/status_by_trackingcode/' . urlencode($code)); }

    // ── Balance ──
    
    public function getBalance() { return $this->http('GET', '/get_balance'); }

    // ── Return Requests ──
    
    /**
     * Create a return request
     * @param array $data Must include one of: consignment_id, invoice, tracking_code
     */
    public function createReturnRequest(array $data) { return $this->http('POST', '/return-request', $data); }
    public function getReturnRequest($id) { return $this->http('GET', '/return-request/' . intval($id)); }
    public function getReturnRequests() { return $this->http('GET', '/return-requests'); }

    // ── Payments ──
    
    public function getPayments() { return $this->http('GET', '/payments'); }
    public function getPayment($id) { return $this->http('GET', '/payments/' . intval($id)); }

    // ── Police Stations (cached 24h — static data) ──
    
    public function getPoliceStations() {
        return courierCacheStatic('steadfast_policestations', function() {
            return $this->http('GET', '/policestations');
        }, 86400);
    }

    // ── Utility: Upload order from our DB ──
    
    /**
     * Upload a single order from our database to Steadfast
     * @param int $orderId Our order ID
     * @param array $overrides Optional overrides for cod_amount, note, etc.
     * @return array Result with success/error info
     */
    public function uploadOrder(int $orderId, array $overrides = []) {
        $order = $this->db->fetch("SELECT * FROM orders WHERE id = ?", [$orderId]);
        if (!$order) throw new \Exception("Order #{$orderId} not found");
        
        // Build order data
        $sendProductNames = $this->setting('steadfast_send_product_names') !== '0';
        $note = $overrides['note'] ?? ($order['notes'] ?? '');
        
        // Append product names if enabled
        if ($sendProductNames && empty($overrides['note'])) {
            try {
                $items = $this->db->fetchAll("SELECT product_name, quantity FROM order_items WHERE order_id = ?", [$orderId]);
                $itemDesc = [];
                foreach ($items as $item) {
                    $itemDesc[] = $item['product_name'] . ($item['quantity'] > 1 ? ' x' . $item['quantity'] : '');
                }
                if ($itemDesc) {
                    $note = implode(', ', $itemDesc) . ($note ? ' | ' . $note : '');
                }
            } catch (\Throwable $e) {}
        }
        
        $codAmount = $overrides['cod_amount'] ?? (($order['payment_method'] === 'cod') ? floatval($order['total']) : 0);
        
        $data = [
            'invoice'           => $order['order_number'],
            'recipient_name'    => $order['customer_name'],
            'recipient_phone'   => $order['customer_phone'],
            'recipient_address' => $order['customer_address'],
            'cod_amount'        => $codAmount,
            'note'              => $note,
        ];
        
        $resp = $this->createOrder($data);
        
        // Parse response
        if (!empty($resp['consignment']['consignment_id'])) {
            $cid = $resp['consignment']['consignment_id'];
            $trackingCode = $resp['consignment']['tracking_code'] ?? $cid;
            
            // Update order
            $this->db->update('orders', [
                'courier_name'           => 'Steadfast',
                'courier_consignment_id' => $cid,
                'courier_tracking_id'    => $trackingCode,
                'courier_status'         => 'pending',
                'courier_uploaded_at'    => date('Y-m-d H:i:s'),
                'order_status'           => 'ready_to_ship',
                'updated_at'             => date('Y-m-d H:i:s'),
            ], 'id = ?', [$orderId]);
            
            // Log upload
            try {
                $this->db->insert('courier_uploads', [
                    'order_id'         => $orderId,
                    'courier_provider' => 'steadfast',
                    'consignment_id'   => $cid,
                    'tracking_id'      => $trackingCode,
                    'status'           => 'uploaded',
                    'response_data'    => json_encode($resp),
                ]);
            } catch (\Throwable $e) {}
            
            // Log status change
            try {
                $this->db->insert('order_status_history', [
                    'order_id' => $orderId,
                    'status'   => 'ready_to_ship',
                    'note'     => "Uploaded to Steadfast. CID: {$cid}",
                ]);
            } catch (\Throwable $e) {}
            
            return [
                'success'        => true,
                'consignment_id' => $cid,
                'tracking_code'  => $trackingCode,
                'message'        => $resp['message'] ?? 'Uploaded successfully',
            ];
        }
        
        return [
            'success' => false,
            'message' => $resp['message'] ?? $resp['errors'] ?? 'Upload failed',
            'raw'     => $resp,
        ];
    }

    /**
     * Bulk upload multiple orders
     * @param array $orderIds Array of order IDs
     * @return array Results summary
     */
    public function bulkUploadOrders(array $orderIds) {
        $results = ['success' => 0, 'failed' => 0, 'errors' => [], 'uploaded' => []];
        
        // For small batches, use individual uploads (more reliable)
        if (count($orderIds) <= 5) {
            foreach ($orderIds as $oid) {
                try {
                    $r = $this->uploadOrder(intval($oid));
                    if ($r['success']) {
                        $results['success']++;
                        $results['uploaded'][] = ['order_id' => $oid, 'consignment_id' => $r['consignment_id']];
                    } else {
                        $results['failed']++;
                        $results['errors'][] = "Order #{$oid}: " . ($r['message'] ?? 'Failed');
                    }
                } catch (\Throwable $e) {
                    $results['failed']++;
                    $results['errors'][] = "Order #{$oid}: " . $e->getMessage();
                }
            }
            return $results;
        }
        
        // For larger batches, use bulk API
        $orders = [];
        $orderMap = [];
        foreach ($orderIds as $oid) {
            $oid = intval($oid);
            $order = $this->db->fetch("SELECT * FROM orders WHERE id = ?", [$oid]);
            if (!$order) { $results['failed']++; $results['errors'][] = "Order #{$oid}: Not found"; continue; }
            
            $note = $order['notes'] ?? '';
            if ($this->setting('steadfast_send_product_names') !== '0') {
                try {
                    $items = $this->db->fetchAll("SELECT product_name, quantity FROM order_items WHERE order_id = ?", [$oid]);
                    $names = array_map(fn($i) => $i['product_name'] . ($i['quantity'] > 1 ? ' x' . $i['quantity'] : ''), $items);
                    if ($names) $note = implode(', ', $names) . ($note ? ' | ' . $note : '');
                } catch (\Throwable $e) {}
            }
            
            $orderData = [
                'invoice'           => $order['order_number'],
                'recipient_name'    => $order['customer_name'],
                'recipient_phone'   => $order['customer_phone'],
                'recipient_address' => $order['customer_address'],
                'cod_amount'        => ($order['payment_method'] === 'cod') ? floatval($order['total']) : 0,
                'note'              => $note,
            ];
            $orders[] = $orderData;
            $orderMap[$order['order_number']] = $oid;
        }
        
        if (empty($orders)) return $results;
        
        $resp = $this->bulkCreateOrder($orders);
        
        // Process bulk response (array of results)
        if (is_array($resp)) {
            foreach ($resp as $item) {
                $invoice = $item['invoice'] ?? '';
                $oid = $orderMap[$invoice] ?? null;
                if (!$oid) continue;
                
                if (!empty($item['consignment_id']) && ($item['status'] ?? '') === 'success') {
                    $cid = $item['consignment_id'];
                    $trackingCode = $item['tracking_code'] ?? $cid;
                    
                    $this->db->update('orders', [
                        'courier_name'           => 'Steadfast',
                        'courier_consignment_id' => $cid,
                        'courier_tracking_id'    => $trackingCode,
                        'courier_status'         => 'pending',
                        'courier_uploaded_at'    => date('Y-m-d H:i:s'),
                        'order_status'           => 'ready_to_ship',
                        'updated_at'             => date('Y-m-d H:i:s'),
                    ], 'id = ?', [$oid]);
                    
                    try { $this->db->insert('courier_uploads', ['order_id'=>$oid,'courier_provider'=>'steadfast','consignment_id'=>$cid,'tracking_id'=>$trackingCode,'status'=>'uploaded','response_data'=>json_encode($item)]); } catch (\Throwable $e) {}
                    try { $this->db->insert('order_status_history', ['order_id'=>$oid,'status'=>'ready_to_ship','note'=>"Bulk uploaded to Steadfast. CID: {$cid}"]); } catch (\Throwable $e) {}
                    
                    $results['success']++;
                    $results['uploaded'][] = ['order_id' => $oid, 'consignment_id' => $cid];
                } else {
                    $results['failed']++;
                    $results['errors'][] = "#{$invoice}: " . ($item['message'] ?? 'Failed');
                }
            }
        }
        
        return $results;
    }

    /**
     * Sync status for a single order from Steadfast
     */
    public function syncOrderStatus(int $orderId) {
        $order = $this->db->fetch("SELECT * FROM orders WHERE id = ?", [$orderId]);
        if (!$order) throw new \Exception("Order not found");
        
        $cid = $order['courier_consignment_id'] ?? '';
        if (empty($cid)) {
            // Try by invoice
            $resp = $this->getStatusByInvoice($order['order_number']);
        } else {
            $resp = $this->getStatusByCid($cid);
        }
        
        $deliveryStatus = $resp['delivery_status'] ?? $resp['data']['delivery_status'] ?? null;
        if (empty($deliveryStatus)) {
            return ['success' => false, 'message' => 'No status returned', 'raw' => $resp];
        }
        
        $courierStatus = $deliveryStatus;
        $trackingMessage = $resp['tracking_message'] ?? $resp['data']['tracking_message'] ?? '';
        $deliveryCharge = $resp['delivery_charge'] ?? $resp['data']['delivery_charge'] ?? null;
        $codAmount = $resp['cod_amount'] ?? $resp['data']['cod_amount'] ?? null;
        
        // Map Steadfast status to our status
        $statusMap = [
            'pending'                           => null,
            'in_review'                         => 'shipped',
            'delivered'                         => 'delivered',
            'delivered_approval_pending'         => 'delivered',
            'partial_delivered'                 => 'partial_delivered',
            'partial_delivered_approval_pending' => 'partial_delivered',
            'cancelled'                         => 'pending_cancel',
            'cancelled_approval_pending'        => 'pending_cancel',
            'hold'                              => 'on_hold',
            'unknown'                           => null,
            'unknown_approval_pending'          => null,
        ];
        
        $newStatus = $statusMap[$courierStatus] ?? null;
        $updateData = [
            'courier_status'           => $courierStatus,
            'courier_tracking_message' => $trackingMessage,
            'updated_at'               => date('Y-m-d H:i:s'),
        ];
        if ($deliveryCharge !== null) $updateData['courier_delivery_charge'] = floatval($deliveryCharge);
        if ($codAmount !== null)      $updateData['courier_cod_amount'] = floatval($codAmount);
        
        // Don't overwrite terminal statuses
        $terminal = ['delivered', 'returned', 'cancelled'];
        if ($newStatus && !in_array($order['order_status'], $terminal)) {
            $updateData['order_status'] = $newStatus;
            if ($newStatus === 'delivered') $updateData['delivered_at'] = date('Y-m-d H:i:s');
        }
        
        $this->db->update('orders', $updateData, 'id = ?', [$orderId]);
        
        if ($newStatus === 'delivered') {
            try { awardOrderCredits($orderId); } catch (\Throwable $e) {}
        }
        
        return [
            'success'         => true,
            'courier_status'  => $courierStatus,
            'our_status'      => $newStatus ?? $order['order_status'],
            'tracking_message'=> $trackingMessage,
            'message'         => "Synced: {$courierStatus}" . ($trackingMessage ? " - {$trackingMessage}" : ''),
        ];
    }

    /**
     * Get the Steadfast portal URL for a consignment
     */
    public static function portalUrl($consignmentId) {
        return 'https://steadfast.com.bd/user/consignment/' . urlencode($consignmentId);
    }

    /**
     * Get the tracking URL for customers
     */
    public static function trackingUrl($trackingCode) {
        return 'https://steadfast.com.bd/t/' . urlencode($trackingCode);
    }

    // ── HTTP Client ──
    
    private function http($method, $path, $data = []) {
        if (!$this->isConfigured()) throw new \Exception('Steadfast API not configured');
        
        // Rate limit check (80 req/min for Steadfast)
        if (!courierRateCheck('steadfast', courierRateLimit('steadfast'))) {
            throw new \Exception('Steadfast rate limit reached (80/min) — try again shortly');
        }

        $url = $this->baseUrl . $path;
        $headers = [
            'Api-Key: ' . $this->apiKey,
            'Secret-Key: ' . $this->secretKey,
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
        }
        
        // Execute with exponential backoff retry on 429/503
        $result = courierCurlExec($ch, 'steadfast', "{$method} {$path}", 5);
        curl_close($ch);
        
        if ($result['error']) throw new \Exception("Steadfast API error: {$result['error']}");
        
        $httpCode = intval($result['http_code'] ?? 0);
        $decoded = json_decode($result['response'], true);
        
        // Handle HTTP error codes with clear messages
        if ($httpCode === 401 || $httpCode === 403) {
            $apiErr = $decoded['message'] ?? $decoded['error'] ?? '';
            $keyHint = $this->apiKey ? substr($this->apiKey, 0, 6) . '...' . substr($this->apiKey, -4) : '(empty)';
            throw new \Exception("Steadfast authentication failed (HTTP {$httpCode}). API Key: {$keyHint}. Re-enter credentials in Settings." . ($apiErr ? " — {$apiErr}" : ''));
        }
        if ($httpCode >= 400 && !is_array($decoded)) {
            throw new \Exception("Steadfast returned HTTP {$httpCode}. Response: " . substr($result['response'] ?? '', 0, 200));
        }
        if (!is_array($decoded)) throw new \Exception("Invalid response from Steadfast (HTTP {$httpCode})");
        
        return $decoded;
    }
}
