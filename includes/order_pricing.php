<?php
/**
 * BLOOMINOUS - Server-side Order Pricing (shared)
 *
 * The client (web checkout, BloominousApp) only says WHICH products and
 * HOW MANY. Everything that decides money — the product's real name,
 * price, stock, and the subtotal — is read here from Firestore. This is
 * what stops a modified page or app from sending "subtotal: 1" for a
 * ₱2,000 order (Payment Fraud, Mutemi & Bacao 2024 §4.2.1; OWASP API6).
 *
 * Cart item contract (see assets/script/bloom_cart.js):
 *   { id: "<inventory doc id>", qty: 2, branchId: "<source branch>" | null, ... }
 * `productId` / `quantity` are also accepted, so a slightly different app
 * cart model still works. Client-sent `price` and `name` are IGNORED.
 *
 * Where a product is looked up, first match wins:
 *   1. branches/{item.branchId}/inventory/{id}   (the branch the cart says)
 *   2. branches/{deliveryBranch}/inventory/{id}  (the branch delivering it)
 *   3. every other branches/{b}/inventory/{id}   (shop.php groups products
 *                                                 across branches by name)
 *   4. inventory/{id}                            (old top-level collection)
 * Whichever document is found, its price is a real price from the database.
 *
 * Money is added up in whole CENTAVOS (integers), never in floating-point
 * pesos, so totals can't drift by a fraction of a centavo.
 */

require_once __DIR__ . '/firestore_rest.php';

const BLOOM_ORDER_MAX_LINES = 50;         // same cap as the carts rule in firestore.rules
const BLOOM_ORDER_MAX_QTY_PER_LINE = 99;  // per product, per order

/**
 * Thrown when an order can't be priced because of the CART (bad item,
 * out of stock, ...). Carries a machine-readable code and the HTTP status
 * submit_order.php should answer with. Infrastructure failures (Firestore
 * down) are NOT this class — they bubble up as normal errors.
 */
class BloomOrderPricingException extends \RuntimeException
{
    public $errorCode;
    public $httpStatus;

    public function __construct(string $message, string $errorCode, int $httpStatus = 409)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->httpStatus = $httpStatus;
    }
}

/**
 * Firestore's own rules for a document ID: not empty, no "/", not "." or
 * "..", at most 1,500 bytes. Checked BEFORE the ID goes into a path, so a
 * crafted ID can never point the lookup at a different collection.
 */
function bloom_is_valid_doc_id($id): bool
{
    return is_string($id)
        && $id !== ''
        && $id !== '.'
        && $id !== '..'
        && strlen($id) <= 1500
        && strpos($id, '/') === false;
}

/**
 * Finds a product's inventory document using the lookup order described at
 * the top of this file. Returns ['data' => fields, 'branchId' => ?string],
 * or null if the product exists nowhere.
 */
function bloom_find_inventory_product(string $productId, array $preferredBranchIds): ?array
{
    // Listed once per request, and only if the preferred branches miss.
    static $allBranchIds = null;

    $tried = [];
    $lookup = function ($branchId) use ($productId, &$tried): ?array {
        if (!bloom_is_valid_doc_id($branchId) || isset($tried[$branchId])) {
            return null;
        }
        $tried[$branchId] = true;
        $doc = bloom_firestore_get_document_by_path_rest("branches/{$branchId}/inventory/{$productId}");
        return $doc === null ? null : ['data' => $doc, 'branchId' => $branchId];
    };

    foreach ($preferredBranchIds as $branchId) {
        if ($found = $lookup($branchId)) {
            return $found;
        }
    }

    if ($allBranchIds === null) {
        $allBranchIds = array_column(bloom_firestore_list_documents_rest('branches'), 'id');
    }
    foreach ($allBranchIds as $branchId) {
        if ($found = $lookup($branchId)) {
            return $found;
        }
    }

    $legacy = bloom_firestore_get_document_rest('inventory', $productId);
    return $legacy === null ? null : ['data' => $legacy, 'branchId' => null];
}

/**
 * Turns the client's cart items into server-priced order lines.
 *
 * Returns:
 *   [
 *     'items'    => [ ['id', 'name', 'price', 'qty', 'lineTotal', 'branchId', 'image'], ... ],
 *     'subtotal' => float (pesos, exact to the centavo),
 *   ]
 *
 * @throws BloomOrderPricingException for any problem with the cart itself.
 */
function bloom_price_order_items($rawItems, string $deliveryBranchId): array
{
    if (!is_array($rawItems) || $rawItems === []) {
        throw new BloomOrderPricingException('Your cart is empty.', 'EMPTY_CART', 400);
    }

    // --- Step 1: read only WHICH product and HOW MANY from each line.
    // Lines for the same product are merged, so one product can't dodge
    // the per-product limit by appearing twice.
    $wanted = []; // productId => ['qty' => int, 'branchHint' => ?string]
    foreach (array_values($rawItems) as $raw) {
        if (!is_array($raw)) {
            throw new BloomOrderPricingException('Your cart has an invalid item. Please refresh your cart and try again.', 'INVALID_ITEM', 400);
        }

        $productId = (string) ($raw['id'] ?? $raw['productId'] ?? '');
        if (!bloom_is_valid_doc_id($productId)) {
            throw new BloomOrderPricingException('Your cart has an invalid item. Please refresh your cart and try again.', 'INVALID_ITEM', 400);
        }

        $qtyRaw = $raw['qty'] ?? $raw['quantity'] ?? null;
        if (!is_numeric($qtyRaw) || floor((float) $qtyRaw) != (float) $qtyRaw || (int) $qtyRaw < 1) {
            throw new BloomOrderPricingException('Each item needs a quantity of at least 1.', 'INVALID_QUANTITY', 400);
        }

        $branchHint = isset($raw['branchId']) && is_string($raw['branchId']) ? $raw['branchId'] : null;

        if (!isset($wanted[$productId])) {
            $wanted[$productId] = ['qty' => 0, 'branchHint' => $branchHint];
        }
        $wanted[$productId]['qty'] += (int) $qtyRaw;
    }

    if (count($wanted) > BLOOM_ORDER_MAX_LINES) {
        throw new BloomOrderPricingException('Your cart has too many different items for one order.', 'TOO_MANY_ITEMS', 400);
    }

    // --- Step 2: look up each product and price it from the database.
    $pricedItems = [];
    $subtotalCents = 0;

    foreach ($wanted as $productId => $line) {
        if ($line['qty'] > BLOOM_ORDER_MAX_QTY_PER_LINE) {
            throw new BloomOrderPricingException('You can order at most ' . BLOOM_ORDER_MAX_QTY_PER_LINE . ' of one product per order.', 'QUANTITY_LIMIT', 400);
        }

        $found = bloom_find_inventory_product($productId, [$line['branchHint'], $deliveryBranchId]);
        if ($found === null) {
            throw new BloomOrderPricingException('An item in your cart is no longer available. Please remove it and try again.', 'PRODUCT_UNAVAILABLE');
        }

        $product = $found['data'];
        $name = (string) ($product['name'] ?? 'Unnamed');

        if (!isset($product['price']) || !is_numeric($product['price']) || (float) $product['price'] < 0) {
            throw new BloomOrderPricingException("{$name} can't be ordered online right now. Please remove it and try again.", 'PRODUCT_UNAVAILABLE');
        }

        // Stock is checked only when the product actually tracks it.
        if (isset($product['stock']) && is_numeric($product['stock']) && (int) $product['stock'] < $line['qty']) {
            $left = max(0, (int) $product['stock']);
            $message = $left === 0
                ? "{$name} is out of stock. Please remove it and try again."
                : "Only {$left} left of {$name}. Please lower the quantity and try again.";
            throw new BloomOrderPricingException($message, 'OUT_OF_STOCK');
        }

        $priceCents = (int) round((float) $product['price'] * 100);
        $lineCents = $priceCents * $line['qty'];
        $subtotalCents += $lineCents;

        $pricedItems[] = [
            'id'        => $productId,
            'name'      => $name,
            'price'     => $priceCents / 100,
            'qty'       => $line['qty'],
            'lineTotal' => $lineCents / 100,
            'branchId'  => $found['branchId'],
            'image'     => isset($product['image']) && is_string($product['image']) ? $product['image'] : null,
        ];
    }

    return [
        'items'    => $pricedItems,
        'subtotal' => $subtotalCents / 100,
    ];
}