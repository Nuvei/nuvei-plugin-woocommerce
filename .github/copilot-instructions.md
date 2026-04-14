# Nuvei Payments for WooCommerce – Copilot Instructions

## Architecture Overview

Entry point is `nuvei-payments-for-woocommerce.php`, bootstrapped via two hooks:
- `plugins_loaded` – registers the gateway class, declares HPOS and Blocks compatibility
- `init` – wires up all remaining WC/WP hooks and REST routes

A custom autoloader (`includes/class-nuvei-pfw-autoloader.php`) maps class names to files: `Nuvei_Pfw_Foo` → `class-nuvei-pfw-foo.php`, searching `includes/` then `includes/abstracts/`. All plugin constants live in `config.php`.

### Class Hierarchy

```
Nuvei_Pfw_Request  (abstract — includes/abstracts/class-nuvei-pfw-request.php)
    ├── Nuvei_Pfw_Open_Order       — openOrder API call (session token + order create)
    ├── Nuvei_Pfw_Update_Order     — updateOrder API call (cart/address changes)
    ├── Nuvei_Pfw_Payment          — payment.do API call (used for subscription renewals)
    ├── Nuvei_Pfw_Settle_Void      — settle or void a transaction
    ├── Nuvei_Pfw_Refund           — refund request
    ├── Nuvei_Pfw_Session_Token    — getSessionToken API call
    ├── Nuvei_Pfw_Get_Apms         — fetch available payment methods
    ├── Nuvei_Pfw_Subscription     — create Nuvei subscription
    ├── Nuvei_Pfw_Subscription_Cancel
    ├── Nuvei_Pfw_Create_Plan      — create a payment plan
    ├── Nuvei_Pfw_Download_Plans   — download plans JSON (sc_plans.json)
    ├── Nuvei_Pfw_Notify_Url       — processes inbound DMN (webhook) callbacks
    └── Nuvei_Pfw_Helper           — thin public wrapper exposing protected Request methods
                                     to Gateway and Cashier code (no API calls of its own)

Nuvei_Pfw_Gateway extends WC_Payment_Gateway   — gateway id "nuvei"
Nuvei_Pfw_Gateway_Blocks_Support               — WC Blocks integration
Nuvei_Pfw_String                               — static string/URL utilities
Nuvei_Pfw_Http                                 — static input sanitisation
Nuvei_Pfw_Logger                               — static logger
Nuvei_Payments_For_Woocommerce                 — static bootstrap class (main plugin file)
```

### Payment Flow

1. **Checkout** – JS calls `/nuvei/api/v1/get-checkout-data/` (classic) or `/get-simply-connect-data/` (Simply Connect) → `Nuvei_Pfw_Open_Order::process()` → calls Nuvei `openOrder` API → stores result in WC session under `nuvei_last_open_order_details`.
2. **Payment** – Nuvei Simply Connect or Cashier SDK renders in the `#nuvei_checkout_container` div and submits card data directly to Nuvei.
3. **DMN webhook** – Nuvei POSTs to `?wc-api=nuvei_listener` (also accepts legacy `sc_listener`). `Nuvei_Pfw_Notify_Url::process()` validates the checksum and updates the WC order status.
4. **Admin actions** – Settle, Void, and Refund are triggered from the order screen via admin REST endpoints.

### REST API Endpoints (`nuvei/api/v1` prefix, defined in `NUVEI_API_PATH`)

| Route | Method | Auth | Purpose |
|---|---|---|---|
| `/get-checkout-data/` | POST | public | openOrder data for classic checkout |
| `/get-simply-connect-data/` | POST | public | openOrder data for Simply Connect |
| `/pre-payment/` | POST | public | Pre-payment validation |
| `/pay-for-existing-order/` | POST | public | Pay from My Account → Orders |
| `/get-cashier-link/` | POST | public | Cashier redirect URL |
| `/cancel-order/` | POST | admin | Void transaction |
| `/settle-order/` | POST | admin | Settle transaction |
| `/refund-order/` | POST | admin | Refund transaction |
| `/cancel-subs/` | POST | admin | Cancel Nuvei subscription |
| `/download-subs-plans/` | GET | admin | Download subscription plans |
| `/dismiss-sys-msg/` | POST | admin | Dismiss admin notice |
| `/get-payment-custom-msg/` | GET | admin | Retrieve custom payment message |

Admin routes use the `check_admin_or_store_owner` permission callback.

---

## Key Conventions

### Adding a New API Request Class
1. Create `includes/class-nuvei-pfw-<name>.php`.
2. Extend `Nuvei_Pfw_Request`.
3. Implement `process()` and `get_checksum_params()`. The latter must return an **ordered array** of field names whose concatenated values are hashed (SHA-256 or MD5, per the `hash_type` gateway setting) to produce the `checksum` field.
4. Call `$this->call_rest_api( 'api-endpoint-name', $params )` — the base class appends the checksum and POSTs via `wp_remote_post` to `NUVEI_PFW_REST_ENDPOINT_INT` (test) or `NUVEI_PFW_REST_ENDPOINT_PROD`.

### Input Sanitisation
Always use `Nuvei_Pfw_Http::get_param( $key, $type )` instead of accessing `$_REQUEST` directly. Types: `string` (default, uses `sanitize_text_field`), `int`, `float`, `email`/`mail` (uses `sanitize_email`), `array`.

### Logging
Use `Nuvei_Pfw_Logger::write( $data, $message, $log_level )` throughout. Logs land in `wp-content/uploads/nuvei-logs/`. The logger:
- Is a no-op when logging is disabled in plugin settings.
- Automatically masks PII fields (names, emails, addresses, IPs) defined in its `$fields_to_mask` map.
- Pretty-prints JSON only in test mode.

### Constants
All constants are in `config.php`, prefixed `NUVEI_PFW_*`. Order meta keys start with `_nuvei` (WC hidden-field convention). The gateway name constant `NUVEI_PFW_GATEWAY_NAME = 'nuvei'`; legacy orders may have `'sc'` as their payment method — both are valid.

### Gateway Settings Structure
Settings are split across three initialiser methods in `Nuvei_Pfw_Gateway`:
- `init_form_base_fields()` – credentials (merchantId, merchantSiteId, secret, hash_type), test mode, payment action
- `init_form_advanced_fields()` – integration type (Simply Connect vs Cashier), APM block-list, subscription options
- `init_form_tools_fields()` – logging toggles, plans download button, custom payment message

Fields with `'required' => true` cause the gateway to auto-disable on save if left blank.

Custom admin setting element types follow the WooCommerce pattern `generate_{type}_html` — e.g., `generate_payment_plans_btn_html` renders the Download Plans button and uses a template from `templates/admin/`.

### WooCommerce Blocks
`Nuvei_Pfw_Gateway_Blocks_Support` extends `AbstractPaymentMethodType`. The plugin declares compatibility with both `custom_order_tables` (HPOS) and `cart_checkout_blocks` via `FeaturesUtil::declare_compatibility`.

### Subscriptions
Declared supports in `Nuvei_Pfw_Gateway`: `subscriptions`, `subscription_cancellation`, `subscription_suspension`, `subscription_reactivation`, `subscription_amount_changes`, `subscription_date_changes`. Renewal payments fire on `woocommerce_scheduled_subscription_payment_nuvei`. After a rebilling payment, `do_action('nuvei_pfwc_after_rebilling_payment')` fires — use this hook for post-renewal logic.

---

## JavaScript (assets/js/nuvei_public.js)

### Custom DOM Events
The plugin dispatches two custom events from `nuvei_public.js` that external scripts can listen to. These events are for the **Classic Checkout only**. Place listeners in the footer (e.g. via WP Header and Footer plugin), wrapped in `<script>` tags.

| Event | Cancelable | Fired when |
|---|---|---|
| `nuveiPfw:isCheckoutClassicFormValidEvent` | **yes** | Inside `nuveiIsCheckoutClassicFormValid()` before any validation runs |
| `nuveiPfw:onPageLoadEvent` | no | After `jQuery(function($){…})` + SDK check, when all plugin events are wired |

**Stopping the checkout flow** — call `e.preventDefault()` on the cancelable event:
```js
document.addEventListener('nuveiPfw:isCheckoutClassicFormValidEvent', function(e) {
    if (myConditionFails) {
        e.preventDefault(); // causes nuveiIsCheckoutClassicFormValid() to return false
    }
});
```

See `examples/breakdance-multistep-checkout.js` for a real-world example (Breakdance multi-step checkout where the Simply Connect container is hidden until the final step).

### Key JS globals (set before jQuery ready)
- `scTrans` — PHP-localised object (`wp_localize_script`): `apiUrl`, `checkoutIntegration`, `paymentGatewayName`, translations, etc.
- `nuveiCheckoutSdkParams` — built up incrementally and passed to `simplyConnect.init()`.
- `nuveiGetCheckoutData()` — debounced (350 ms) + in-flight guard; aborts stale requests via `AbortController`. Call this to trigger an `openOrder` refresh.
- `nuveiDestroySimplyConnect()` — tears down the SDK iframe; call before re-initialising.

---

## PHP Action Hooks

| Hook | Args | Fired when |
|---|---|---|
| `nuvei_pfwc_after_rebilling_payment` | — | After a successful subscription renewal payment via DMN |
| `nuvei_pfwc_after_auto_void` | `$tr_id`, `$email` | After the plugin automatically voids a duplicate/failed transaction |

---

## Examples Directory

`examples/` contains ready-made integration snippets for third-party themes/plugins. They are **not loaded by the plugin** — users paste them into a code snippet plugin (footer). Current examples:

- `breakdance-multistep-checkout.js` — wires `nuveiPfw:isCheckoutClassicFormValidEvent` and `nuveiPfw:onPageLoadEvent` to handle Breakdance multi-step checkout where `#nuvei_checkout_container` is hidden until the payment step.
