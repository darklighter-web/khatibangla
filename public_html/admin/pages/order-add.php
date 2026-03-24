<?php
require_once __DIR__ . '/../../includes/session.php';
$pageTitle = 'Create Order';
require_once __DIR__ . '/../includes/auth.php';

$db = Database::getInstance();

/* ─── POST: Create Order ─── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_order') {
        $productIds   = $_POST['item_product_id']   ?? [];
        $productNames = $_POST['item_product_name']  ?? [];
        $variantNames = $_POST['item_variant_name']  ?? [];
        $quantities   = $_POST['item_qty']           ?? [];
        $prices       = $_POST['item_price']         ?? [];

        if (empty($productIds)) {
            redirect(adminUrl('pages/order-add.php?msg=no_items'));
        }

        // Calculate totals
        $subtotal = 0;
        $orderItems = [];
        foreach ($productIds as $i => $pid) {
            $qty   = max(1, intval($quantities[$i] ?? 1));
            $price = floatval($prices[$i] ?? 0);
            $line  = $price * $qty;
            $subtotal += $line;

            $pName = sanitize($productNames[$i] ?? '');
            if (!$pName && $pid) {
                $pRow = $db->fetch("SELECT name FROM products WHERE id = ?", [intval($pid)]);
                $pName = $pRow['name'] ?? 'Product';
            }

            $orderItems[] = [
                'product_id'   => intval($pid) ?: null,
                'product_name' => $pName,
                'variant_name' => sanitize($variantNames[$i] ?? '') ?: null,
                'price'        => $price,
                'quantity'     => $qty,
                'subtotal'     => $line,
            ];
        }

        $shipping = floatval($_POST['shipping_cost'] ?? 80);
        $discount = floatval($_POST['discount_amount'] ?? 0);
        $advance  = floatval($_POST['advance_amount'] ?? 0);
        $total    = max(0, $subtotal + $shipping - $discount - $advance);

        // Find or create customer
        $phone = sanitize($_POST['customer_phone'] ?? '');
        $name  = sanitize($_POST['customer_name'] ?? '');
        $email = sanitize($_POST['customer_email'] ?? '');

        $customer = $db->fetch("SELECT * FROM customers WHERE phone = ?", [$phone]);
        if (!$customer) {
            $db->insert('customers', [
                'name'  => $name,
                'phone' => $phone,
                'email' => $email,
            ]);
            $customerId = $db->lastInsertId();
        } else {
            $customerId = $customer['id'];
            if ($name) $db->update('customers', ['name' => $name], 'id = ?', [$customerId]);
        }

        $deliveryMethod = sanitize($_POST['delivery_method'] ?? 'Pathao Courier');
        $channel        = sanitize($_POST['channel'] ?? 'phone');
        $isPreorder     = !empty($_POST['is_preorder']) ? 1 : 0;
        $preorderDate   = !empty($_POST['preorder_date']) ? $_POST['preorder_date'] : null;
        $shippingNote   = sanitize($_POST['shipping_note'] ?? '');
        $orderNote      = sanitize($_POST['order_note'] ?? '');
        $panelNote      = sanitize($_POST['panel_note'] ?? '');
        $status         = sanitize($_POST['order_status'] ?? 'confirmed');

        // Create order
        $orderNumber = generateOrderNumber();
        $db->insert('orders', [
            'order_number'     => $orderNumber,
            'customer_id'      => $customerId,
            'customer_name'    => $name,
            'customer_phone'   => $phone,
            'customer_email'   => $email,
            'customer_address' => sanitize($_POST['customer_address'] ?? ''),
            'customer_city'    => sanitize($_POST['shipping_city'] ?? ''),
            'subtotal'         => $subtotal,
            'shipping_cost'    => $shipping,
            'discount_amount'  => $discount,
            'advance_amount'   => $advance,
            'total'            => $total,
            'payment_method'   => 'cod',
            'order_status'     => $status,
            'shipping_method'  => $deliveryMethod,
            'courier_name'     => $deliveryMethod,
            'channel'          => $channel,
            'is_preorder'      => $isPreorder,
            'preorder_date'    => $preorderDate,
            'panel_notes'      => $panelNote ?: null,
            'order_note'       => $orderNote ?: null,
            'notes'            => $shippingNote,
            'ip_address'       => getClientIP(),
        ]);
        $orderId = $db->lastInsertId();

        // Insert items
        foreach ($orderItems as $oi) {
            $db->insert('order_items', [
                'order_id'     => $orderId,
                'product_id'   => $oi['product_id'],
                'product_name' => $oi['product_name'],
                'variant_name' => $oi['variant_name'],
                'price'        => $oi['price'],
                'quantity'     => $oi['quantity'],
                'subtotal'     => $oi['subtotal'],
            ]);
        }

        // Status history
        $db->insert('order_status_history', [
            'order_id'   => $orderId,
            'status'     => $status,
            'note'       => 'Order created manually by admin',
            'changed_by' => getAdminId(),
        ]);

        // Accounting entry
        try {
            $db->insert('accounting_entries', [
                'entry_type'     => 'income',
                'amount'         => $total,
                'reference_type' => 'order',
                'reference_id'   => $orderId,
                'description'    => "Order $orderNumber (manual)",
                'entry_date'     => date('Y-m-d'),
            ]);
        } catch (\Throwable $e) {}

        logActivity(getAdminId(), 'create', 'orders', $orderId);
        redirect(adminUrl("pages/order-management.php?msg=created&highlight=$orderId"));
    }
}

require_once __DIR__ . '/../includes/header.php';
$msg = $_GET['msg'] ?? '';
?>

<?php if ($msg === 'no_items'): ?>
<div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4 text-sm">Please add at least one product to create an order.</div>
<?php endif; ?>

<!-- ══════════════════════════ HEADER BAR ══════════════════════════ -->
<div class="flex flex-wrap items-center gap-3 mb-4">
    <a href="<?= adminUrl('pages/order-management.php') ?>" class="p-1.5 rounded hover:bg-gray-100 transition">
        <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
    </a>
    <h2 class="text-base font-bold text-gray-800">Create New Order</h2>
    <div class="flex items-center gap-1.5 ml-auto flex-wrap text-xs">
        <span class="text-gray-500"><?= date('M d, Y h:i A') ?></span>
        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-blue-100 text-blue-700">NEW</span>
        <span class="bg-gray-100 px-2 py-0.5 rounded text-[10px] font-medium">MANUAL</span>
    </div>
</div>

<!-- ══════════════════════════ MAIN LAYOUT ══════════════════════════ -->
<form method="POST" id="orderForm">
<input type="hidden" name="action" value="create_order">
<div class="flex flex-col lg:flex-row gap-5">

    <!-- ────────── LEFT COLUMN ────────── -->
    <div class="flex-1 min-w-0 space-y-4">

        <!-- ▸ Customer Info Card -->
        <div class="bg-white border border-gray-200 rounded-lg p-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-x-5 gap-y-3">
                <div>
                    <label class="block text-sm font-semibold text-gray-800 mb-1.5">Mobile Number</label>
                    <div class="flex items-center gap-2">
                        <input type="text" name="customer_phone" id="custPhone" required placeholder="01XXXXXXXXX" class="flex-1 px-3 py-2 border border-gray-200 rounded-md text-sm focus:border-blue-400 focus:ring-1 focus:ring-blue-100 outline-none" oninput="lookupCustomer(this.value)">
                        <button type="button" onclick="lookupCustomer(document.getElementById('custPhone').value)" class="text-blue-600 hover:text-blue-700 text-sm" title="Lookup"><i class="fas fa-search"></i></button>
                    </div>
                    <div id="custLookupResult" class="hidden mt-1 text-[10px]"></div>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-800 mb-1.5">Name</label>
                    <input type="text" name="customer_name" id="custName" required class="w-full px-3 py-2 border border-gray-200 rounded-md text-sm focus:border-blue-400 focus:ring-1 focus:ring-blue-100 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-800 mb-1.5">Delivery Method</label>
                    <select name="delivery_method" class="w-full px-3 py-2 border border-gray-200 rounded-md text-sm focus:border-blue-400 focus:ring-1 focus:ring-blue-100 outline-none">
                        <?php foreach(['Pathao Courier','Steadfast','CarryBee','RedX','SA Paribahan','Sundarban','Self Delivery','Store Pickup'] as $dm): ?>
                        <option value="<?= $dm ?>"><?= $dm ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- ▸ Address / Extra Options Card -->
        <div class="bg-white border border-gray-200 rounded-lg p-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-5 gap-y-3">
                <div>
                    <label class="block text-sm font-semibold text-gray-800 mb-1.5">Address</label>
                    <textarea name="customer_address" id="custAddress" rows="2" required class="w-full px-3 py-2 border border-gray-200 rounded-md text-sm focus:border-blue-400 focus:ring-1 focus:ring-blue-100 outline-none resize-none" placeholder="Full delivery address..."></textarea>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-800 mb-1.5">Extra Options</label>
                    <div class="flex items-center gap-2 mb-2">
                        <select name="channel" class="flex-1 px-2 py-1.5 border border-gray-200 rounded-md text-xs">
                            <?php foreach(['phone'=>'📞 Phone','facebook'=>'📘 Facebook','whatsapp'=>'💬 WhatsApp','website'=>'🌐 WEB','instagram'=>'📷 Instagram','other'=>'📌 Other'] as $cv=>$cl): ?>
                            <option value="<?= $cv ?>" <?= $cv==='phone'?'selected':'' ?>><?= $cl ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <input type="email" name="customer_email" placeholder="Email (optional)" class="w-full px-2 py-1.5 border border-gray-200 rounded-md text-xs focus:border-blue-400 outline-none">
                </div>
            </div>
        </div>

        <!-- ▸ Three Notes + Preorder Card -->
        <div class="bg-white border border-gray-200 rounded-lg p-4">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <!-- Shipping Note -->
                <div>
                    <label class="flex items-center gap-1.5 text-sm font-semibold text-gray-800 mb-1.5">
                        <span class="w-2 h-2 rounded-full bg-orange-400"></span> Shipping Note
                        <button type="button" onclick="showNoteTpl('shipping_note','note_tpl_shipping')" class="ml-1 w-5 h-5 rounded bg-orange-100 text-orange-500 hover:bg-orange-200 flex items-center justify-center text-[10px] font-bold" title="Insert template">+</button>
                    </label>
                    <textarea name="shipping_note" rows="3" class="w-full px-3 py-2 border border-orange-200 rounded-md text-sm focus:border-orange-400 focus:ring-1 focus:ring-orange-100 outline-none resize-none bg-orange-50/30" placeholder="***No Exchange or Return***"></textarea>
                    <p class="text-[9px] text-orange-400 mt-1"><i class="fas fa-truck mr-0.5"></i> Sent to courier only</p>
                </div>
                <!-- Order Note -->
                <div>
                    <label class="flex items-center gap-1.5 text-sm font-semibold text-gray-800 mb-1.5">
                        <span class="w-2 h-2 rounded-full bg-blue-400"></span> Order Note
                        <button type="button" onclick="showNoteTpl('order_note','note_tpl_order')" class="ml-1 w-5 h-5 rounded bg-blue-100 text-blue-500 hover:bg-blue-200 flex items-center justify-center text-[10px] font-bold" title="Insert template">+</button>
                    </label>
                    <textarea name="order_note" rows="3" class="w-full px-3 py-2 border border-blue-200 rounded-md text-sm focus:border-blue-400 focus:ring-1 focus:ring-blue-100 outline-none resize-none bg-blue-50/30" placeholder="Gift message, packaging instructions..."></textarea>
                    <p class="text-[9px] text-blue-400 mt-1"><i class="fas fa-file-invoice mr-0.5"></i> Printed on invoice only</p>
                </div>
                <!-- Panel Note -->
                <div>
                    <label class="flex items-center gap-1.5 text-sm font-semibold text-gray-800 mb-1.5">
                        <span class="w-2 h-2 rounded-full bg-green-500"></span> Panel Note
                        <button type="button" onclick="showNoteTpl('panel_note','note_tpl_panel')" class="ml-1 w-5 h-5 rounded bg-green-100 text-green-500 hover:bg-green-200 flex items-center justify-center text-[10px] font-bold" title="Insert template">+</button>
                    </label>
                    <textarea name="panel_note" rows="3" class="w-full px-3 py-2 border border-green-200 rounded-md text-sm focus:border-green-400 focus:ring-1 focus:ring-green-100 outline-none resize-none bg-green-50/30" placeholder="Internal team note..."></textarea>
                    <p class="text-[9px] text-green-500 mt-1"><i class="fas fa-eye-slash mr-0.5"></i> Internal only</p>
                </div>
                <!-- Preorder -->
                <div>
                    <label class="flex items-center gap-1.5 text-sm font-semibold text-gray-800 mb-1.5">
                        <span class="w-2 h-2 rounded-full bg-purple-400"></span> Preorder
                    </label>
                    <label class="flex items-center gap-2 p-2.5 rounded-md border border-gray-200 bg-gray-50 cursor-pointer transition mb-2" id="preorderLabel">
                        <input type="checkbox" name="is_preorder" value="1" class="rounded text-purple-600 w-4 h-4" onchange="document.getElementById('preorderDateField').classList.toggle('hidden',!this.checked);this.closest('label').classList.toggle('border-purple-300',this.checked);this.closest('label').classList.toggle('bg-purple-50',this.checked);this.closest('label').classList.toggle('border-gray-200',!this.checked);this.closest('label').classList.toggle('bg-gray-50',!this.checked)">
                        <span class="text-sm font-medium text-gray-700">Mark as Preorder</span>
                    </label>
                    <div id="preorderDateField" class="hidden">
                        <label class="block text-xs text-gray-600 mb-1">Expected Delivery</label>
                        <input type="date" name="preorder_date" class="w-full px-3 py-2 border border-purple-200 rounded-md text-sm bg-purple-50/50 focus:border-purple-400 focus:ring-1 focus:ring-purple-100">
                    </div>
                </div>
            </div>
        </div>

        <!-- ▸ Products: Ordered + Add -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <!-- Ordered Products -->
            <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
                <div class="px-4 py-2.5 border-b border-gray-100 bg-gray-50 flex items-center justify-between">
                    <h4 class="text-sm font-bold text-gray-800">Ordered Products <span id="itemCount" class="bg-blue-500 text-white text-[10px] px-1.5 py-0.5 rounded-full ml-1">0</span></h4>
                </div>
                <div id="orderedItems" class="divide-y divide-gray-100 max-h-[450px] overflow-y-auto">
                    <div id="noItemsMsg" class="p-8 text-center text-gray-400"><div class="text-2xl mb-1">📦</div><div class="text-sm">No products yet</div></div>
                </div>
            </div>

            <!-- Add Products -->
            <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
                <div class="px-4 py-2.5 border-b border-gray-100 bg-gray-50">
                    <h4 class="text-sm font-bold text-gray-800">Click To Add Products</h4>
                </div>
                <div class="p-3">
                    <div class="grid grid-cols-2 gap-3 mb-3">
                        <div><label class="block text-xs font-semibold text-gray-700 mb-1">Code/SKU</label><input type="text" id="searchSku" placeholder="Type to Search.." class="w-full px-3 py-2 border border-gray-200 rounded-md text-sm" oninput="searchProducts()"></div>
                        <div><label class="block text-xs font-semibold text-gray-700 mb-1">Name</label><input type="text" id="searchName" placeholder="Type to Search.." class="w-full px-3 py-2 border border-gray-200 rounded-md text-sm" oninput="searchProducts()"></div>
                    </div>
                    <div id="productResults" class="divide-y divide-gray-100 max-h-[350px] overflow-y-auto border border-gray-200 rounded-md">
                        <div class="py-4 text-center text-gray-400 text-sm">Type to search products...</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ▸ Totals Card -->
        <div class="bg-white border border-gray-200 rounded-lg p-4">
            <div class="grid grid-cols-2 md:grid-cols-5 gap-4 items-end">
                <div><label class="block text-sm font-semibold text-gray-800 mb-1">Discount</label><input type="number" name="discount_amount" id="discountInput" value="0" min="0" step="1" class="w-full px-3 py-2 border border-gray-200 rounded-md text-sm" oninput="calcTotals()"></div>
                <div><label class="block text-sm font-semibold text-gray-800 mb-1">Advance</label><input type="number" name="advance_amount" id="advanceInput" value="0" min="0" step="1" class="w-full px-3 py-2 border border-gray-200 rounded-md text-sm" oninput="calcTotals()"></div>
                <div><label class="block text-sm font-semibold text-gray-800 mb-1">Sub Total</label><div id="subtotalDisplay" class="px-3 py-2 border border-gray-200 rounded-md text-sm bg-gray-50">0</div></div>
                <div><label class="block text-sm font-semibold text-gray-800 mb-1">DeliveryCharge</label><input type="number" name="shipping_cost" id="shippingInput" value="80" min="0" step="1" class="w-full px-3 py-2 border border-gray-200 rounded-md text-sm" oninput="calcTotals()"></div>
                <div><label class="block text-sm font-semibold text-emerald-600 mb-1 italic">Grand Total</label><div id="grandTotalDisplay" class="px-3 py-2 border border-emerald-300 rounded-md text-sm bg-emerald-50 font-bold text-emerald-700">80</div></div>
            </div>
        </div>

        <!-- ▸ Action Button -->
        <div class="pb-2">
            <button type="submit" class="w-full bg-emerald-500 text-white py-3.5 rounded-xl text-base font-bold hover:bg-emerald-600 transition shadow">Create Order (৳<span id="confirmTotal">80</span>)</button>
        </div>

    </div><!-- END LEFT -->

    <!-- ────────── RIGHT SIDEBAR ────────── -->
    <div class="w-full lg:w-[280px] xl:w-[300px] shrink-0 space-y-4">

        <!-- Order Summary -->
        <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
            <div class="px-4 py-2.5 border-b border-gray-100 bg-gray-50 flex items-center justify-between">
                <span class="text-sm font-semibold text-gray-800">Order Summary</span>
                <span class="text-[10px] text-gray-400">New Order</span>
            </div>
            <div class="p-4 text-xs space-y-1.5">
                <div class="flex justify-between"><span class="text-gray-500">Date</span><span><?= date('M d, Y, h:i A') ?></span></div>
                <div class="flex justify-between items-center"><span class="text-gray-500">Status</span><span class="font-bold px-2 py-0.5 rounded text-[10px] bg-blue-100 text-blue-700">NEW</span></div>
                <div class="flex justify-between"><span class="text-gray-500">Payment</span><span>COD</span></div>
                <div class="flex justify-between items-center"><span class="text-gray-500">Source</span><span id="sidebarSource">📞 Phone</span></div>
                <div class="border-t border-gray-100 my-1.5"></div>
                <div class="flex justify-between"><span class="text-gray-500">Subtotal</span><span id="sideSubtotal">0</span></div>
                <div class="flex justify-between"><span class="text-gray-500">Delivery</span><span id="sideDelivery">80</span></div>
                <div class="flex justify-between" id="sideDiscountRow" style="display:none"><span class="text-gray-500">Discount</span><span id="sideDiscount" class="text-red-600">0</span></div>
                <div class="flex justify-between" id="sideAdvanceRow" style="display:none"><span class="text-gray-500">Advance</span><span id="sideAdvance" class="text-blue-600">0</span></div>
                <div class="flex justify-between font-bold text-sm pt-1 border-t border-gray-100"><span>Total</span><span id="sideTotal">80</span></div>
            </div>
        </div>

        <!-- Sidebar: Order Items compact -->
        <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
            <div class="px-4 py-2 border-b border-gray-100 bg-gray-50 text-xs font-semibold text-gray-700">Order Items</div>
            <div id="sidebarItems" class="p-3 space-y-2">
                <div class="text-xs text-gray-400 text-center py-2">No items added</div>
            </div>
        </div>

        <!-- Order Settings -->
        <div class="bg-white border border-gray-200 rounded-lg p-3 space-y-2">
            <div class="text-xs font-semibold text-gray-700">Order Settings</div>
            <select name="order_status" class="w-full px-3 py-2 border border-gray-200 rounded-md text-sm">
                <option value="confirmed" selected>Confirmed</option>
                <option value="processing">Processing</option>
                <option value="pending">Pending</option>
            </select>
            <div class="flex items-center justify-between">
                <a href="<?= adminUrl('pages/order-management.php') ?>" class="text-xs text-gray-500 hover:text-gray-700">← Back to List</a>
            </div>
        </div>

        <!-- Customer Lookup Info -->
        <div id="customerInfoCard" class="bg-white border border-gray-200 rounded-lg p-3 hidden">
            <div class="text-xs font-semibold text-gray-700 mb-2">Customer History</div>
            <div id="customerStats" class="text-[10px] text-gray-500 space-y-1"></div>
        </div>

    </div><!-- END RIGHT -->

</div><!-- END FLEX -->
</form>

<script>
const SAPI='<?= SITE_URL ?>/api/search.php?admin=1';
let searchTimer=null, lookupTimer=null;

/* ── Product Search ── */
function searchProducts(){
    clearTimeout(searchTimer);
    const q=(document.getElementById('searchSku').value.trim()||document.getElementById('searchName').value.trim());
    if(q.length<2){document.getElementById('productResults').innerHTML='<div class="py-4 text-center text-gray-400 text-sm">Type to search...</div>';return;}
    searchTimer=setTimeout(async()=>{
        try{
            const r=await(await fetch(SAPI+'&q='+encodeURIComponent(q))).json();
            if(!r.results?.length){document.getElementById('productResults').innerHTML='<div class="py-3 text-center text-gray-400 text-sm">No products found</div>';return;}
            let h='';
            r.results.forEach(p=>{
                h+=`<div class="flex items-center gap-3 p-2.5 hover:bg-blue-50 cursor-pointer transition" onclick="addProduct(${p.id},'${esc(p.name)}',${p.price},'${esc(p.image)}','${esc(p.sku||'')}',${p.stock_quantity??0})">
                    <img src="${p.image}" class="w-12 h-12 rounded object-cover border border-gray-200" onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 40 40%22><text y=%22.9em%22 font-size=%2230%22>📦</text></svg>'">
                    <div class="flex-1 min-w-0"><div class="text-sm font-medium text-gray-800 truncate">${esc(p.name)}</div><div class="text-[10px] text-blue-600 font-bold">${p.sku?'SKU: '+esc(p.sku):''}</div><div class="text-[10px] text-gray-500">Price: ৳${p.price.toLocaleString()} · Stock: ${p.stock_quantity??0}</div></div>
                    <span class="text-yellow-400 text-lg shrink-0">★</span></div>`;
            });
            document.getElementById('productResults').innerHTML=h;
        }catch(e){}
    },300);
}

/* ── Add Product to Order ── */
function addProduct(id,name,price,image,sku,stock){
    const c=document.getElementById('orderedItems'),n=document.getElementById('noItemsMsg');if(n)n.remove();
    const d=document.createElement('div');d.className='p-3 item-row border-t border-gray-100';
    d.innerHTML=`<input type="hidden" name="item_product_id[]" value="${id}"><input type="hidden" name="item_product_name[]" value="${esc(name)}"><input type="hidden" name="item_variant_name[]" value="">
        <div class="flex gap-3"><div class="w-14 h-14 bg-gray-100 rounded-lg overflow-hidden shrink-0"><img src="${image}" class="w-full h-full object-cover" onerror="this.parentElement.innerHTML='📦'"></div>
        <div class="flex-1 min-w-0"><div class="flex justify-between gap-1"><div class="min-w-0"><div class="text-sm font-bold text-gray-800">${sku?esc(sku):''}</div><div class="text-xs text-gray-600 truncate">${esc(name)}</div></div><button type="button" onclick="removeItem(this)" class="text-red-400 hover:text-red-600 shrink-0 p-0.5"><i class="fas fa-trash text-xs"></i></button></div>
        <div class="text-[11px] text-gray-400 mt-0.5">৳${price.toLocaleString()}  Stock: ${stock}</div>
        <div class="flex items-center gap-2 mt-2 text-xs flex-wrap"><span class="text-gray-500">Qty</span><div class="flex items-center"><button type="button" onclick="changeQty(this,-1)" class="w-7 h-7 border border-gray-200 rounded-l text-gray-500 hover:bg-gray-50 font-bold">−</button><input type="number" name="item_qty[]" value="1" min="1" class="w-10 h-7 border-t border-b border-gray-200 text-center text-sm item-qty" oninput="calcTotals()"><button type="button" onclick="changeQty(this,1)" class="w-7 h-7 border border-gray-200 rounded-r text-gray-500 hover:bg-gray-50 font-bold">+</button></div><span class="text-gray-500 ml-1">Price</span><input type="number" name="item_price[]" value="${price}" min="0" step="1" class="w-20 h-7 border border-gray-200 rounded text-center text-sm item-price" oninput="calcTotals()"><span class="text-gray-500 ml-auto">Total</span><span class="item-line-total font-bold">${price.toFixed(2)}</span></div></div></div>`;
    c.appendChild(d);calcTotals();
}

function removeItem(b){b.closest('.item-row').remove();calcTotals();
    if(!document.querySelectorAll('.item-row').length){
        document.getElementById('orderedItems').innerHTML='<div id="noItemsMsg" class="p-8 text-center text-gray-400"><div class="text-2xl mb-1">📦</div><div class="text-sm">No products yet</div></div>';
    }
}
function changeQty(b,d){const i=b.closest('.item-row').querySelector('.item-qty');i.value=Math.max(1,parseInt(i.value||1)+d);calcTotals();}

/* ── Calculate Totals ── */
function calcTotals(){
    let sub=0,cnt=0;
    document.querySelectorAll('.item-row').forEach(r=>{
        const q=parseInt(r.querySelector('.item-qty')?.value||1),p=parseFloat(r.querySelector('.item-price')?.value||0),l=q*p;
        const lt=r.querySelector('.item-line-total');if(lt)lt.textContent=l.toFixed(2);sub+=l;cnt++;
    });
    const disc=parseFloat(document.getElementById('discountInput').value||0);
    const adv=parseFloat(document.getElementById('advanceInput').value||0);
    const ship=parseFloat(document.getElementById('shippingInput').value||0);
    const grand=Math.max(0,sub+ship-disc-adv);

    document.getElementById('subtotalDisplay').textContent=sub.toLocaleString();
    document.getElementById('grandTotalDisplay').textContent=grand.toLocaleString();
    document.getElementById('itemCount').textContent=cnt;
    document.getElementById('confirmTotal').textContent=grand.toLocaleString();

    // Update sidebar summary
    document.getElementById('sideSubtotal').textContent=sub.toLocaleString();
    document.getElementById('sideDelivery').textContent=ship.toLocaleString();
    document.getElementById('sideTotal').textContent=grand.toLocaleString();

    const dr=document.getElementById('sideDiscountRow'),ar=document.getElementById('sideAdvanceRow');
    if(disc>0){dr.style.display='flex';document.getElementById('sideDiscount').textContent='-'+disc.toLocaleString();}else{dr.style.display='none';}
    if(adv>0){ar.style.display='flex';document.getElementById('sideAdvance').textContent=adv.toLocaleString();}else{ar.style.display='none';}

    // Update sidebar items
    updateSidebarItems();
}

function updateSidebarItems(){
    const rows=document.querySelectorAll('.item-row');
    const si=document.getElementById('sidebarItems');
    if(!rows.length){si.innerHTML='<div class="text-xs text-gray-400 text-center py-2">No items added</div>';return;}
    let h='';
    rows.forEach(r=>{
        const name=r.querySelector('input[name="item_product_name[]"]')?.value||'Product';
        const sku=r.querySelector('.text-sm.font-bold')?.textContent||'';
        const price=parseFloat(r.querySelector('.item-price')?.value||0);
        const qty=parseInt(r.querySelector('.item-qty')?.value||1);
        const img=r.querySelector('img')?.src||'';
        h+=`<div class="flex items-center gap-2">
            <div class="w-7 h-7 bg-gray-100 rounded overflow-hidden shrink-0">${img?`<img src="${img}" class="w-full h-full object-cover">`:'<div class="w-full h-full flex items-center justify-center text-[10px]">📦</div>'}</div>
            <div class="flex-1 min-w-0"><div class="text-[10px] text-blue-600 font-medium truncate">${esc(sku)}</div><div class="text-[10px] text-gray-500">৳${price.toLocaleString()}</div></div>
            <span class="text-[10px] text-gray-400 shrink-0">${qty}x</span>
        </div>`;
    });
    si.innerHTML=h;
}

/* ── Customer Lookup ── */
function lookupCustomer(phone){
    clearTimeout(lookupTimer);
    phone=phone.replace(/[^0-9]/g,'');
    if(phone.length<10)return;
    lookupTimer=setTimeout(async()=>{
        const res=document.getElementById('custLookupResult');
        res.classList.remove('hidden');
        res.className='mt-1 text-[10px] bg-yellow-50 text-yellow-700 p-1.5 rounded';
        res.textContent='🔍 Looking up...';
        try{
            const r=await(await fetch('<?= adminUrl("api/courier-lookup.php") ?>?phone='+phone)).json();
            if(r.error){res.className='mt-1 text-[10px] bg-green-50 text-green-700 p-1.5 rounded';res.textContent='✨ New customer';return;}

            // Auto-fill name/address if found
            if(r.customer_name && !document.getElementById('custName').value){
                document.getElementById('custName').value=r.customer_name;
            }
            if(r.customer_address && !document.getElementById('custAddress').value){
                document.getElementById('custAddress').value=r.customer_address;
            }

            const o=r.overall||{};
            res.className='mt-1 text-[10px] bg-blue-50 text-blue-700 p-1.5 rounded';
            res.textContent='📊 '+o.total+' orders, '+o.success+' delivered ('+o.rate+'% success)';

            // Show customer card
            const card=document.getElementById('customerInfoCard');
            const stats=document.getElementById('customerStats');
            card.classList.remove('hidden');
            let sh='<div>Total Orders: <b>'+o.total+'</b></div><div>Delivered: <b class="text-green-600">'+o.success+'</b></div><div>Cancelled: <b class="text-red-600">'+o.cancelled+'</b></div><div>Success Rate: <b>'+o.rate+'%</b></div>';
            if(r.our_record){sh+='<div class="mt-1 pt-1 border-t border-gray-100">Total Spent: <b>৳'+Number(r.our_record.total_spent||0).toLocaleString()+'</b></div><div>Web Cancels: <b>'+r.our_record.web_cancels+'</b></div>';}
            stats.innerHTML=sh;
        }catch(e){
            res.className='mt-1 text-[10px] bg-green-50 text-green-700 p-1.5 rounded';
            res.textContent='✨ New customer';
        }
    },500);
}

/* ── Source label update ── */
document.querySelector('select[name="channel"]')?.addEventListener('change',function(){
    const labels={'phone':'📞 Phone','facebook':'📘 Facebook','whatsapp':'💬 WhatsApp','website':'🌐 Web','instagram':'📷 Instagram','other':'📌 Other'};
    document.getElementById('sidebarSource').textContent=labels[this.value]||this.value;
});

function esc(s){return s?s.replace(/&/g,'&amp;').replace(/'/g,'&#39;').replace(/"/g,'&quot;').replace(/</g,'&lt;'):''}
calcTotals();
</script>
<script>
// Note Template Picker for New Order page
var _noteTplTarget = null;
var _noteTpls = {
    note_tpl_shipping: <?= json_encode(array_filter(array_map('trim', explode("\n", getSetting('note_tpl_shipping', "***No Exchange or Return***\nভাঙ্গলে রিটার্ন হবে না"))))) ?>,
    note_tpl_order: <?= json_encode(array_filter(array_map('trim', explode("\n", getSetting('note_tpl_order', "ধন্যবাদ! আপনার পরবর্তী অর্ডারে ১০% ছাড়"))))) ?>,
    note_tpl_panel: <?= json_encode(array_filter(array_map('trim', explode("\n", getSetting('note_tpl_panel', "ফোন করে কনফার্ম করা হয়েছে\nকাস্টমার রিপিটার — VIP"))))) ?>
};
function showNoteTpl(targetField, tplKey) {
    _noteTplTarget = targetField;
    var tpls = _noteTpls[tplKey] || [];
    if (!tpls.length) { alert('No templates. Add in Settings → Note Templates.'); return; }
    var m = document.getElementById('noteTplModal');
    if (!m) {
        m = document.createElement('div'); m.id = 'noteTplModal';
        m.style.cssText = 'position:fixed;inset:0;z-index:9998;background:rgba(0,0,0,.4);display:flex;align-items:center;justify-content:center';
        m.onclick = function(e){ if(e.target===m) m.style.display='none'; };
        m.innerHTML = '<div style="background:#fff;border-radius:12px;max-width:400px;width:90%;max-height:70vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.15)" onclick="event.stopPropagation()"><div style="padding:12px 16px;border-bottom:1px solid #eee;display:flex;justify-content:space-between;align-items:center"><span style="font-weight:700;font-size:13px">📋 টেমপ্লেট বাছাই করুন</span><button onclick="this.closest(\'#noteTplModal\').style.display=\'none\'" style="background:none;border:none;font-size:18px;cursor:pointer;color:#999">&times;</button></div><div id="noteTplList" style="padding:8px"></div></div>';
        document.body.appendChild(m);
    }
    var list = document.getElementById('noteTplList'); list.innerHTML = '';
    tpls.forEach(function(t){
        var btn = document.createElement('button'); btn.type = 'button';
        btn.style.cssText = 'display:block;width:100%;text-align:left;padding:10px 12px;margin:3px 0;border-radius:8px;border:1px solid #e5e7eb;background:#fafafa;cursor:pointer;font-size:12px;color:#374151';
        btn.textContent = t;
        btn.onmouseover = function(){ this.style.background='#eff6ff'; };
        btn.onmouseout = function(){ this.style.background='#fafafa'; };
        btn.onclick = function(){ var ta = document.querySelector('[name="'+_noteTplTarget+'"]'); if(ta){ta.value=ta.value?ta.value+'\n'+t:t;ta.focus();} m.style.display='none'; };
        list.appendChild(btn);
    });
    m.style.display = 'flex';
}
</script>
<?php include __DIR__ . '/../includes/phone-checker-widget.php'; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
