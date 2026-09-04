# Smaily Recommendation Engine — WordPress Plugin Implementation Guide v1.0

**Adressaat**: Claude agent, kes ehitab WooCommerce plugin'it
**Komplekt**: `API_CONTRACT_v1.0.md` (puhtas REST API spec) + see dokument (WP-spetsiifilised juhised)
**Versioon**: 1.0 — sünkroonitud API_CONTRACT_v1.0-ga
**Avaldatud**: 2026-05-19

See dokument täiendab `API_CONTRACT_v1.0.md`-d WordPress + WooCommerce spetsiifiliste rakendusjuhistega. API-leping ise on platvormi-agnostiline (kehtib ka Shopify, Magento, Presta, Make jaoks). Siin on **WP/WC implementatsiooni-mustrid**.

---

## Sisukord

1. [Tehnilised eeldused](#tehnilised-eeldused)
2. [Plugin-struktuur](#plugin-struktuur)
3. [Sõltuvused (composer)](#sõltuvused-composer)
4. [Settings UI](#settings-ui)
5. [Setup token onboarding](#setup-token-onboarding)
6. [WooCommerce HPOS-tugi](#woocommerce-hpos-tugi)
7. [WP Hooks → API mappingu](#wp-hooks--api-mappingu)
8. [Action Scheduler queue](#action-scheduler-queue)
9. [Browse-events beacon-proxy](#browse-events-beacon-proxy)
10. [Cookie management](#cookie-management)
11. [Multilingual support (WPML/Polylang)](#multilingual-support-wpmlpolylang)
12. [GDPR integration (WP Core hooks)](#gdpr-integration-wp-core-hooks)
13. [Identity merge triggers](#identity-merge-triggers)
14. [Error handling + retry](#error-handling--retry)
15. [Test plan](#test-plan)

---

## Tehnilised eeldused

- **PHP**: ≥ 8.0
- **WordPress**: ≥ 6.2
- **WooCommerce**: ≥ 7.0 (HPOS-aware)
- **MySQL/MariaDB**: ≥ 5.7
- **HTTPS**: kohustuslik (cookies SameSite=Lax + Secure)

Plugin testitud platvormidel: WordPress.org, ManageWP, WP Engine, Kinsta, Hostinger.

---

## Plugin-struktuur

```
smaily-rec-engine/
├── smaily-rec-engine.php           # Main plugin bootstrap
├── composer.json
├── readme.txt
├── uninstall.php
├── languages/
│   └── smaily-rec-engine.pot
├── src/
│   ├── Plugin.php                  # Main plugin class
│   ├── Setup/
│   │   ├── Activator.php           # On plugin activation
│   │   ├── Deactivator.php
│   │   ├── Uninstaller.php
│   │   └── SetupTokenExchange.php  # Token exchange logic
│   ├── Admin/
│   │   ├── SettingsPage.php
│   │   ├── ConnectionTest.php
│   │   └── ErrorLogViewer.php
│   ├── Api/
│   │   ├── EngineClient.php        # HTTP client to engine
│   │   ├── Endpoints.php            # Endpoint URL builders
│   │   └── ResponseHandler.php
│   ├── Hooks/
│   │   ├── ProductSync.php         # save_post_product, etc.
│   │   ├── CustomerSync.php        # user_register, profile_update
│   │   ├── OrderSync.php           # order_status_completed (HPOS-aware)
│   │   └── BeaconScript.php        # wp_footer browse beacon
│   ├── Queue/
│   │   ├── EventQueue.php          # Action Scheduler wrapper
│   │   ├── CatalogBatchJob.php
│   │   ├── CustomerBatchJob.php
│   │   ├── OrderJob.php
│   │   └── BrowseBatchFlush.php
│   ├── Identity/
│   │   ├── CookieManager.php
│   │   ├── SessionManager.php
│   │   ├── IdentityMerger.php
│   │   └── UrlCapture.php
│   ├── Rest/
│   │   └── BeaconEndpoint.php      # /wp-json/smaily-rec/v1/beacon
│   ├── GDPR/
│   │   ├── ExportHandler.php       # WP privacy_personal_data_exporters
│   │   ├── EraseHandler.php        # WP privacy_personal_data_erasers
│   │   └── OptOutFormHandler.php   # Frontend opt-out form
│   ├── I18n/
│   │   ├── MultilingualResolver.php # WPML/Polylang detection
│   │   ├── WPMLAdapter.php
│   │   └── PolylangAdapter.php
│   ├── Hpos/
│   │   ├── HposCompatibility.php   # Declare compatibility
│   │   └── OrderReader.php         # Abstracted order reading
│   └── Utils/
│       ├── Logger.php
│       └── ConfigStore.php         # WP options wrapper
├── assets/
│   ├── js/
│   │   ├── settings.js
│   │   └── beacon.js
│   └── css/
│       └── admin.css
└── tests/                          # PHPUnit
```

---

## Sõltuvused (composer)

`composer.json`:

```json
{
  "name": "smaily/smaily-rec-engine",
  "type": "wordpress-plugin",
  "require": {
    "php": ">=8.0",
    "woocommerce/action-scheduler": "^3.7"
  },
  "require-dev": {
    "phpunit/phpunit": "^9.0",
    "wp-coding-standards/wpcs": "^3.0"
  },
  "autoload": {
    "psr-4": {
      "Smaily\\RecEngine\\": "src/"
    }
  }
}
```

**Action Scheduler** bundeldatakse, mitte ei eeldata, et WC seda annab. See lubab plugin'il töötada **ka ilma WC-ta** (kontaktide sünk, browse-events töötavad ilma e-poeta).

---

## Settings UI

Plugin'i Settings on **WP Admin > Settings > Smaily Rec Engine**. 5 tab'i:

1. **Connection** - setup-token paste + test connection
2. **Catalog Sync** - mapping previewi + manual sync nupp
3. **Browse Tracking** - cookie nimi, sample rate (debug), opt-out URL
4. **GDPR** - audit log, opt-out submissions
5. **Diagnostics** - error log, last-sync timestamps, version mismatch

**Connection tab näide**:

```php
<div class="wrap">
  <h1>Smaily Rec Engine — Connection</h1>
  
  <?php if (!$is_connected): ?>
    <h2>Setup</h2>
    <p>Paste your Setup URL (received from Smaily Rec Engine admin):</p>
    <input type="text" name="smly_setup_url" id="smly-setup-url" 
           placeholder="https://recengine.example.com/setup/abc123xyz" 
           class="regular-text" />
    <button class="button button-primary" id="smly-do-exchange">
      Exchange Token
    </button>
    
    <p class="description">
      Or paste only the token (the part after /setup/):
    </p>
    <input type="text" name="smly_setup_token" id="smly-setup-token" 
           placeholder="abc123xyz" class="regular-text" />
  <?php else: ?>
    <h2>Connected to <?php echo esc_html($tenant_name); ?></h2>
    <table class="form-table">
      <tr>
        <th>Tenant ID</th>
        <td><code><?php echo esc_html($tenant_id); ?></code></td>
      </tr>
      <tr>
        <th>Engine Version</th>
        <td><?php echo esc_html($engine_version); ?></td>
      </tr>
      <tr>
        <th>Connection Status</th>
        <td>
          <span id="smly-connection-status">Checking...</span>
          <button class="button" id="smly-test-connection">Test Connection</button>
        </td>
      </tr>
    </table>
    
    <h3>Disconnect</h3>
    <p>Disconnecting removes the API key. Data already sent to the engine remains.</p>
    <button class="button button-secondary" id="smly-disconnect">Disconnect</button>
  <?php endif; ?>
</div>
```

JavaScript (`assets/js/settings.js`):

```javascript
jQuery(document).ready(function($) {
  $('#smly-do-exchange').on('click', function() {
    const url = $('#smly-setup-url').val() || '';
    const tokenField = $('#smly-setup-token').val() || '';
    
    // Extract token from URL if URL given
    let token = tokenField;
    if (url) {
      const match = url.match(/\/setup\/([a-zA-Z0-9_-]+)/);
      if (match) token = match[1];
    }
    
    if (!token) {
      alert('Please provide a Setup URL or token');
      return;
    }
    
    $.post(ajaxurl, {
      action: 'smly_exchange_setup_token',
      _wpnonce: smly_settings.nonce,
      token: token
    }, function(response) {
      if (response.success) {
        location.reload();
      } else {
        alert('Exchange failed: ' + response.data.message);
      }
    });
  });
  
  $('#smly-test-connection').on('click', function() {
    $('#smly-connection-status').text('Testing...');
    $.post(ajaxurl, {
      action: 'smly_test_connection',
      _wpnonce: smly_settings.nonce
    }, function(response) {
      if (response.success) {
        $('#smly-connection-status').html('<span style="color:green">✓ Connected</span>');
      } else {
        $('#smly-connection-status').html('<span style="color:red">✗ ' + response.data.message + '</span>');
      }
    });
  });
});
```

---

## Setup token onboarding

`src/Admin/SettingsPage.php`:

```php
<?php
namespace Smaily\RecEngine\Admin;

use Smaily\RecEngine\Setup\SetupTokenExchange;
use Smaily\RecEngine\Utils\ConfigStore;

class SettingsPage {
    public function register_ajax_handlers() {
        add_action('wp_ajax_smly_exchange_setup_token', [$this, 'handle_exchange']);
    }
    
    public function handle_exchange() {
        check_ajax_referer('smly_settings');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }
        
        $token = sanitize_text_field($_POST['token'] ?? '');
        if (empty($token)) {
            wp_send_json_error(['message' => 'Token is required']);
        }
        
        $exchanger = new SetupTokenExchange();
        $result = $exchanger->exchange($token);
        
        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => $result->get_error_message(),
                'code' => $result->get_error_code(),
            ]);
        }
        
        // Save config to WP options
        $config = new ConfigStore();
        $config->save_connection([
            'tenant_id' => $result['tenant_id'],
            'tenant_name' => $result['tenant_name'],
            'api_key' => $result['api_key'],  // encrypted
            'engine_base_url' => $result['engine_base_url'],
            'engine_version' => $result['engine_version'],
            'endpoints' => $result['endpoints'],
            'config' => $result['config'],
            'connected_at' => current_time('mysql'),
        ]);
        
        wp_send_json_success([
            'tenant_name' => $result['tenant_name'],
            'engine_version' => $result['engine_version'],
        ]);
    }
}
```

`src/Setup/SetupTokenExchange.php`:

```php
<?php
namespace Smaily\RecEngine\Setup;

class SetupTokenExchange {
    private const SETUP_BASE_URL = 'https://intelligence.smaily.com/setup/exchange';
    
    public function exchange(string $token) {
        // Hard-coded engine base URL for first call (no engine_base_url in config yet)
        $url = self::SETUP_BASE_URL;
        
        $plugin_info = [
            'name' => 'smaily-rec-engine',
            'version' => SMLY_REC_VERSION,
            'platform' => 'wordpress',
            'platform_version' => get_bloginfo('version'),
            'ecommerce_platform' => class_exists('WooCommerce') ? 'woocommerce' : null,
            'ecommerce_platform_version' => defined('WC_VERSION') ? WC_VERSION : null,
            'site_url' => home_url(),
        ];
        
        $response = wp_remote_post($url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => sprintf('SmailyRecEngine-WooPlugin/%s', SMLY_REC_VERSION),
            ],
            'body' => wp_json_encode([
                'setup_token' => $token,
                'plugin_info' => $plugin_info,
            ]),
            'timeout' => 30,
        ]);
        
        if (is_wp_error($response)) {
            return new \WP_Error('http_error', $response->get_error_message());
        }
        
        $status = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($status !== 200) {
            return new \WP_Error(
                $body['error'] ?? 'exchange_failed',
                $body['message'] ?? 'Unknown error'
            );
        }
        
        return $body;
    }
}
```

---

## WooCommerce HPOS-tugi

**HPOS = High-Performance Order Storage** (WooCommerce 8.2+). Orders kuvatakse `wp_wc_orders` tabelis (mitte `wp_posts`). Plugin peab kasutama **WC abstractions**, mitte raw SQL'i.

**Compatibility declaration** (`smaily-rec-engine.php`):

```php
<?php
/**
 * Plugin Name: Smaily Rec Engine
 * Version: 1.0.0
 * Requires PHP: 8.0
 * Requires at least: 6.2
 * WC requires at least: 7.0
 * WC tested up to: 9.0
 */

add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables', __FILE__, true
        );
    }
});
```

**Order reading** (`src/Hpos/OrderReader.php`):

```php
<?php
namespace Smaily\RecEngine\Hpos;

class OrderReader {
    public function get_order(int $order_id): ?\WC_Order {
        // Use wc_get_order() — automatically HPOS-aware
        $order = wc_get_order($order_id);
        return $order instanceof \WC_Order ? $order : null;
    }
    
    public function build_engine_payload(\WC_Order $order): array {
        $items = [];
        foreach ($order->get_items() as $item) {
            /** @var \WC_Order_Item_Product $item */
            $product = $item->get_product();
            if (!$product) continue;
            
            $sku = $product->get_sku();
            if (empty($sku)) continue;  // Skip products without SKU
            
            $items[] = [
                'sku' => $sku,
                'qty' => (int) $item->get_quantity(),
                'unit_price' => (float) ($item->get_total() / $item->get_quantity()),
                'line_total' => (float) $item->get_total(),
                'discount_amount' => (float) $item->get_subtotal() - (float) $item->get_total(),
            ];
        }
        
        $customer_email = $order->get_billing_email();
        
        // Extract attribution from order meta (set during checkout)
        $smaily_rec_id = $order->get_meta('_smaily_rec_id');
        $smaily_visitor_token = $order->get_meta('_smaily_visitor_token');
        $smaily_rec_ctx = $order->get_meta('_smaily_rec_ctx');
        $session_id = $order->get_meta('_smaily_anon_session_id');
        
        return [
            'external_order_id' => 'WC-' . $order->get_id(),
            'customer_email' => $customer_email,
            'ordered_at' => $order->get_date_completed()
                ? $order->get_date_completed()->format('c')
                : $order->get_date_created()->format('c'),
            'total_amount' => (float) $order->get_total(),
            'discount_amount' => (float) $order->get_total_discount(),
            'currency' => $order->get_currency(),
            'status' => 'completed',
            'smaily_rec_id' => $smaily_rec_id ?: null,
            'smaily_visitor_token' => $smaily_visitor_token ?: null,
            'smaily_rec_ctx' => $smaily_rec_ctx ?: null,
            'session_id' => $session_id ?: null,
            'items' => $items,
        ];
    }
}
```

---

## WP Hooks → API mappingu

| WP/WC Hook | Plugin action | API endpoint |
|------------|---------------|--------------|
| `save_post_product` | enqueue catalog batch | `POST /api/v1/ingest/catalog` |
| `woocommerce_product_set_stock_status` | enqueue catalog batch | `POST /api/v1/ingest/catalog` |
| `delete_post` (post_type=product) | enqueue catalog batch (in_stock=false) | `POST /api/v1/ingest/catalog` |
| `user_register` | enqueue customer | `POST /api/v1/ingest/customers` |
| `profile_update` | enqueue customer | `POST /api/v1/ingest/customers` |
| `woocommerce_order_status_completed` | enqueue order (HPOS-aware) | `POST /api/v1/ingest/orders` |
| `wp_login` | check identity merge needed | `POST /api/v1/identity/merge` |
| `woocommerce_checkout_order_processed` | save attribution metadata to order | (no API call) |
| `wp_footer` (frontend) | output beacon `<script>` | beacon proxy via WP REST |
| `wp_privacy_personal_data_exporter` | filter for GDPR export | `GET /api/v1/customer/{email}/export` |
| `wp_privacy_personal_data_eraser` | filter for GDPR delete | `DELETE /api/v1/customer/{email}` |

**Batch-imine**: catalog/customers/orders mitte saadetakse otse hook'ist. Saadame **Action Scheduler queue'sse**, mis batch'b ja saadab tahapoole (1-min flush).

---

## Action Scheduler queue

**Browse-events** = beacon-proxy + 30s batch flush (vt allpool, eraldi mehhanism).

**Catalog / customers / orders** = Action Scheduler async actions.

`src/Queue/EventQueue.php`:

```php
<?php
namespace Smaily\RecEngine\Queue;

class EventQueue {
    public function enqueue_catalog_update(int $product_id): void {
        // Avoid duplicates — only enqueue once per minute per product
        $hook_args = ['product_id' => $product_id];
        if (as_next_scheduled_action('smly_sync_catalog_product', $hook_args)) {
            return;
        }
        
        as_enqueue_async_action('smly_sync_catalog_product', $hook_args, 'smaily-rec-catalog');
    }
    
    public function enqueue_customer_update(int $user_id): void {
        $hook_args = ['user_id' => $user_id];
        if (as_next_scheduled_action('smly_sync_customer', $hook_args)) {
            return;
        }
        
        as_enqueue_async_action('smly_sync_customer', $hook_args, 'smaily-rec-customers');
    }
    
    public function enqueue_order(int $order_id): void {
        $hook_args = ['order_id' => $order_id];
        as_enqueue_async_action('smly_sync_order', $hook_args, 'smaily-rec-orders');
    }
}

// Handlers
add_action('smly_sync_catalog_product', [new CatalogBatchJob(), 'process']);
add_action('smly_sync_customer', [new CustomerBatchJob(), 'process']);
add_action('smly_sync_order', [new OrderJob(), 'process']);
```

`src/Queue/CatalogBatchJob.php` näide:

```php
<?php
namespace Smaily\RecEngine\Queue;

use Smaily\RecEngine\Api\EngineClient;

class CatalogBatchJob {
    public function process(int $product_id): void {
        $product = wc_get_product($product_id);
        if (!$product || !$product->get_sku()) {
            return;
        }
        
        // Handle variable products
        if ($product->is_type('variable')) {
            foreach ($product->get_children() as $variation_id) {
                $this->process($variation_id);  // recursive
            }
            return;
        }
        
        $payload = $this->build_product_payload($product);
        
        $client = new EngineClient();
        $response = $client->post('/api/v1/ingest/catalog', [
            'products' => [$payload],
        ]);
        
        if (is_wp_error($response)) {
            // Retry handled by Action Scheduler's built-in retry mechanism
            throw new \RuntimeException($response->get_error_message());
        }
    }
    
    private function build_product_payload(\WC_Product $product): array {
        // ... build payload per API_CONTRACT_v1.0 catalog schema
        return [
            'sku' => $product->get_sku(),
            'name' => $product->get_name(),
            'category_path' => $this->get_primary_category_path($product),
            'price' => (float) $product->get_price(),
            'compare_price' => $product->get_regular_price() != $product->get_price() 
                ? (float) $product->get_regular_price() 
                : null,
            'on_sale_until' => $product->get_date_on_sale_to() 
                ? $product->get_date_on_sale_to()->format('c') 
                : null,
            'in_stock' => $product->is_in_stock(),
            'description' => wp_strip_all_tags($product->get_short_description()),
            'image_url' => wp_get_attachment_url($product->get_image_id()) ?: null,
            'product_url' => get_permalink($product->get_id()),
            'external_id' => (string) $product->get_id(),
            'tags' => $this->build_best_effort_tags($product),
            'raw_attributes' => $this->extract_raw_attributes($product),
        ];
    }
}
```

**Action Scheduler retry**: built-in. Kui job ebaõnnestub, AS retry'b 5 korda eksponentsi-backoff'iga (1m, 2m, 4m, 8m, 16m). Pärast 5-t katset job läheb `failed` staatusele - admin saab WP-Admin UI's manual'lt re-queue'd.

---

## Browse-events beacon-proxy

**Probleem**: kui plugin saadab `wp_footer` PHP-curl'iga browse-event mootorile, lehe-renderdamine blokeerub. Mitte vastuvõetav.

**Lahendus**: **client-side beacon** + **server-side proxy**.

### Frontend beacon (`assets/js/beacon.js`):

```javascript
(function() {
  'use strict';
  
  if (!window.SmailyRec || !window.SmailyRec.beacon_url) return;
  
  // Build event from page context (passed via wp_localize_script)
  const event = window.SmailyRec.event;
  if (!event) return;
  
  // Add session ID from cookie
  event.session_id = getCookie(window.SmailyRec.session_cookie_name) 
    || generateSessionId();
  
  // Add visitor token + rec_id + ctx from cookies if present
  const vt = getCookie(window.SmailyRec.tracking_cookie_name);
  const rec = getCookie(window.SmailyRec.rec_id_cookie_name);
  const ctx = getCookie(window.SmailyRec.context_cookie_name);
  
  if (vt) event.smaily_visitor_token = vt;
  if (rec) event.smaily_rec_id = rec;
  if (ctx) event.smaily_ctx = ctx;
  
  // Add customer email if logged in
  if (window.SmailyRec.customer_email) {
    event.customer_email = window.SmailyRec.customer_email;
    event.external_id = window.SmailyRec.user_id;
  }
  
  // Generate UUID v4 for event_id
  event.event_id = generateUuid();
  
  // Send via beacon (non-blocking)
  const payload = JSON.stringify({ events: [event] });
  
  if (navigator.sendBeacon) {
    const blob = new Blob([payload], { type: 'application/json' });
    navigator.sendBeacon(window.SmailyRec.beacon_url, blob);
  } else {
    // Fallback for older browsers
    fetch(window.SmailyRec.beacon_url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: payload,
      keepalive: true,
    }).catch(function() {});
  }
  
  function getCookie(name) {
    const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
    return match ? decodeURIComponent(match[2]) : null;
  }
  
  function generateSessionId() {
    const sid = generateUuid();
    const ttl = window.SmailyRec.session_ttl_days * 86400;
    document.cookie = window.SmailyRec.session_cookie_name + '=' + sid 
      + '; max-age=' + ttl + '; path=/; SameSite=Lax; Secure';
    return sid;
  }
  
  function generateUuid() {
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
      const r = Math.random() * 16 | 0;
      return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
    });
  }
})();
```

### Server-side WP REST endpoint (`src/Rest/BeaconEndpoint.php`):

```php
<?php
namespace Smaily\RecEngine\Rest;

use Smaily\RecEngine\Utils\ConfigStore;

class BeaconEndpoint {
    public function register() {
        add_action('rest_api_init', function() {
            register_rest_route('smaily-rec/v1', '/beacon', [
                'methods' => 'POST',
                'permission_callback' => '__return_true',  // public
                'callback' => [$this, 'handle_beacon'],
            ]);
        });
    }
    
    public function handle_beacon(\WP_REST_Request $request) {
        // Origin validation (CORS-style)
        $origin = $request->get_header('origin');
        $site_url = home_url();
        if ($origin && strpos($origin, parse_url($site_url, PHP_URL_HOST)) === false) {
            return new \WP_Error('invalid_origin', 'Origin not allowed', ['status' => 403]);
        }
        
        $events = $request->get_param('events');
        if (!is_array($events)) {
            return new \WP_REST_Response(null, 400);
        }
        
        // Buffer events in transient (avoid creating AS row per event)
        $buffered = get_transient('smly_rec_beacon_buffer') ?: [];
        $buffered = array_merge($buffered, $events);
        set_transient('smly_rec_beacon_buffer', $buffered, 60);
        
        // Schedule flush if not already scheduled
        if (!as_next_scheduled_action('smly_rec_flush_beacon_buffer')) {
            as_schedule_single_action(time() + 30, 'smly_rec_flush_beacon_buffer', [], 'smaily-rec-browse');
        }
        
        // Respond 204 No Content immediately
        return new \WP_REST_Response(null, 204);
    }
}

// Flush handler
add_action('smly_rec_flush_beacon_buffer', function() {
    $events = get_transient('smly_rec_beacon_buffer');
    if (empty($events)) return;
    delete_transient('smly_rec_beacon_buffer');
    
    $config = new ConfigStore();
    $client = new \Smaily\RecEngine\Api\EngineClient();
    
    // Send to engine in chunks of 100
    foreach (array_chunk($events, 100) as $batch) {
        $response = $client->post('/api/v1/ingest/browse', ['events' => $batch]);
        if (is_wp_error($response)) {
            // Log error but don't retry (browse events tolerate 5-10% loss)
            error_log('Smaily Rec browse send failed: ' . $response->get_error_message());
        }
    }
});
```

### wp_footer hook (`src/Hooks/BeaconScript.php`):

```php
<?php
namespace Smaily\RecEngine\Hooks;

class BeaconScript {
    public function register() {
        add_action('wp_footer', [$this, 'output_beacon_script']);
    }
    
    public function output_beacon_script() {
        $event = $this->build_event_for_current_page();
        if (!$event) return;
        
        wp_enqueue_script(
            'smly-rec-beacon',
            plugins_url('assets/js/beacon.js', SMLY_REC_PLUGIN_FILE),
            [],
            SMLY_REC_VERSION,
            true
        );
        
        $config = (new ConfigStore())->get_connection();
        
        wp_localize_script('smly-rec-beacon', 'SmailyRec', [
            'beacon_url' => rest_url('smaily-rec/v1/beacon'),
            'event' => $event,
            'tracking_cookie_name' => $config['config']['tracking_cookie_name'],
            'session_cookie_name' => $config['config']['session_cookie_name'],
            'rec_id_cookie_name' => $config['config']['rec_id_cookie_name'],
            'context_cookie_name' => $config['config']['context_cookie_name'],
            'session_ttl_days' => $config['config']['session_ttl_days'],
            'customer_email' => is_user_logged_in() 
                ? wp_get_current_user()->user_email 
                : null,
            'user_id' => is_user_logged_in() 
                ? (string) get_current_user_id() 
                : null,
        ]);
    }
    
    private function build_event_for_current_page(): ?array {
        if (is_product()) {
            global $product;
            return [
                'event_type' => 'product_view',
                'sku' => $product ? $product->get_sku() : null,
                'event_ts' => gmdate('c'),
                'source' => 'plugin_woo',
            ];
        }
        
        if (is_product_category()) {
            $term = get_queried_object();
            return [
                'event_type' => 'category_view',
                'category_path' => $this->get_category_path($term),
                'event_ts' => gmdate('c'),
                'source' => 'plugin_woo',
            ];
        }
        
        if (is_search()) {
            return [
                'event_type' => 'search',
                'search_query' => get_search_query(),
                'event_ts' => gmdate('c'),
                'source' => 'plugin_woo',
            ];
        }
        
        return null;  // Not a tracked page
    }
}
```

---

## Cookie management

`src/Identity/UrlCapture.php`:

```php
<?php
namespace Smaily\RecEngine\Identity;

use Smaily\RecEngine\Utils\ConfigStore;

class UrlCapture {
    public function register() {
        add_action('init', [$this, 'capture_url_params'], 1);
    }
    
    public function capture_url_params() {
        $config = (new ConfigStore())->get_connection();
        if (!$config) return;
        
        $url_params = [
            'smaily_vt' => $config['config']['tracking_cookie_name'],
            'smaily_rec' => $config['config']['rec_id_cookie_name'],
            'smaily_ctx' => $config['config']['context_cookie_name'],
        ];
        
        $ttl_days = [
            'smaily_vt' => $config['config']['cookie_ttl_days'],
            'smaily_rec' => $config['config']['rec_id_ttl_days'],
            'smaily_ctx' => $config['config']['context_ttl_days'],
        ];
        
        foreach ($url_params as $param => $cookie_name) {
            if (isset($_GET[$param])) {
                $value = sanitize_text_field($_GET[$param]);
                setcookie(
                    $cookie_name,
                    $value,
                    [
                        'expires' => time() + $ttl_days[$param] * 86400,
                        'path' => '/',
                        'secure' => is_ssl(),
                        'samesite' => 'Lax',
                        'httponly' => false,  // JS access needed for beacon
                    ]
                );
                $_COOKIE[$cookie_name] = $value;  // available immediately
            }
        }
    }
}
```

---

## Multilingual support (WPML/Polylang)

`src/I18n/MultilingualResolver.php`:

```php
<?php
namespace Smaily\RecEngine\I18n;

class MultilingualResolver {
    public function get_adapter() {
        if (defined('ICL_SITEPRESS_VERSION')) {
            return new WPMLAdapter();
        }
        if (function_exists('pll_languages_list')) {
            return new PolylangAdapter();
        }
        return null;  // Single-language site
    }
    
    public function get_product_translations(int $product_id): array {
        $adapter = $this->get_adapter();
        if (!$adapter) {
            return [
                'name' => get_the_title($product_id),
                'description' => get_post_field('post_excerpt', $product_id),
                'product_url' => get_permalink($product_id),
            ];
        }
        
        return $adapter->get_translations($product_id);
    }
}
```

`src/I18n/WPMLAdapter.php`:

```php
<?php
namespace Smaily\RecEngine\I18n;

class WPMLAdapter {
    public function get_translations(int $product_id): array {
        $name = [];
        $description = [];
        $url = [];
        
        $languages = apply_filters('wpml_active_languages', null);
        
        foreach ($languages as $lang_code => $lang_info) {
            $translated_id = apply_filters('wpml_object_id', $product_id, 'product', false, $lang_code);
            if ($translated_id) {
                $name[$lang_code] = get_the_title($translated_id);
                $description[$lang_code] = get_post_field('post_excerpt', $translated_id);
                $url[$lang_code] = apply_filters('wpml_permalink', get_permalink($translated_id), $lang_code);
            }
        }
        
        return [
            'name' => $name,
            'description' => array_filter($description),  // remove empty
            'product_url' => $url,
        ];
    }
}
```

`src/I18n/PolylangAdapter.php`:

```php
<?php
namespace Smaily\RecEngine\I18n;

class PolylangAdapter {
    public function get_translations(int $product_id): array {
        $name = [];
        $description = [];
        $url = [];
        
        foreach (pll_languages_list() as $lang) {
            $translated_id = pll_get_post($product_id, $lang);
            if ($translated_id) {
                $name[$lang] = get_the_title($translated_id);
                $description[$lang] = get_post_field('post_excerpt', $translated_id);
                $url[$lang] = get_permalink($translated_id);
            }
        }
        
        return [
            'name' => $name,
            'description' => array_filter($description),
            'product_url' => $url,
        ];
    }
}
```

CatalogBatchJob kasutab seda:

```php
$resolver = new MultilingualResolver();
$translations = $resolver->get_product_translations($product->get_id());

$payload['name'] = $translations['name'];  // string or {lang: string}
$payload['description'] = $translations['description'];
$payload['product_url'] = $translations['product_url'];
```

---

## GDPR integration (WP Core hooks)

`src/GDPR/ExportHandler.php`:

```php
<?php
namespace Smaily\RecEngine\GDPR;

class ExportHandler {
    public function register() {
        add_filter('wp_privacy_personal_data_exporters', [$this, 'register_exporter']);
    }
    
    public function register_exporter(array $exporters): array {
        $exporters['smaily-rec-engine'] = [
            'exporter_friendly_name' => __('Smaily Recommendation Engine', 'smaily-rec-engine'),
            'callback' => [$this, 'export_data'],
        ];
        return $exporters;
    }
    
    public function export_data(string $email, int $page = 1): array {
        $client = new \Smaily\RecEngine\Api\EngineClient();
        $response = $client->get('/api/v1/customer/' . urlencode($email) . '/export');
        
        if (is_wp_error($response)) {
            return ['data' => [], 'done' => true];
        }
        
        // Convert engine response to WP exporter format
        $export_items = $this->format_for_wp_exporter($response);
        
        return [
            'data' => $export_items,
            'done' => true,  // single-page (engine returns all data)
        ];
    }
    
    // ... format conversion logic
}
```

`src/GDPR/EraseHandler.php`:

```php
<?php
namespace Smaily\RecEngine\GDPR;

class EraseHandler {
    public function register() {
        add_filter('wp_privacy_personal_data_erasers', [$this, 'register_eraser']);
    }
    
    public function register_eraser(array $erasers): array {
        $erasers['smaily-rec-engine'] = [
            'eraser_friendly_name' => __('Smaily Recommendation Engine', 'smaily-rec-engine'),
            'callback' => [$this, 'erase_data'],
        ];
        return $erasers;
    }
    
    public function erase_data(string $email, int $page = 1): array {
        $client = new \Smaily\RecEngine\Api\EngineClient();
        $response = $client->delete('/api/v1/customer/' . urlencode($email), [
            'confirm' => true,
            'reason' => 'user_request',
        ]);
        
        if (is_wp_error($response)) {
            return [
                'items_removed' => false,
                'items_retained' => false,
                'messages' => [$response->get_error_message()],
                'done' => true,
            ];
        }
        
        return [
            'items_removed' => true,
            'items_retained' => false,
            'messages' => [
                sprintf(
                    __('Removed %d records from Smaily Recommendation Engine', 'smaily-rec-engine'),
                    array_sum($response['records_removed'])
                ),
            ],
            'done' => true,
        ];
    }
}
```

---

## Identity merge triggers

`src/Identity/IdentityMerger.php`:

```php
<?php
namespace Smaily\RecEngine\Identity;

class IdentityMerger {
    public function register() {
        // Trigger 1: user login
        add_action('wp_login', [$this, 'on_user_login'], 10, 2);
        
        // Trigger 2: checkout with email provided
        add_action('woocommerce_checkout_order_processed', [$this, 'on_checkout'], 10, 1);
    }
    
    public function on_user_login(string $user_login, \WP_User $user) {
        $this->maybe_merge_identity($user->user_email, 'user_logged_in');
    }
    
    public function on_checkout(int $order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;
        
        $email = $order->get_billing_email();
        if (!$email) return;
        
        // Save attribution cookies to order meta for later use
        $order->update_meta_data('_smaily_anon_session_id', $_COOKIE['smaily_anon_sid'] ?? '');
        $order->update_meta_data('_smaily_visitor_token', $_COOKIE['smaily_rec_uid'] ?? '');
        $order->update_meta_data('_smaily_rec_id', $_COOKIE['smaily_rec_id'] ?? '');
        $order->update_meta_data('_smaily_rec_ctx', $_COOKIE['smaily_rec_ctx'] ?? '');
        $order->save();
        
        $this->maybe_merge_identity($email, 'email_provided_at_checkout');
    }
    
    private function maybe_merge_identity(string $email, string $reason) {
        $config = (new \Smaily\RecEngine\Utils\ConfigStore())->get_connection();
        if (!$config) return;
        
        $anon_session_id = $_COOKIE[$config['config']['session_cookie_name']] ?? null;
        $visitor_token = $_COOKIE[$config['config']['tracking_cookie_name']] ?? null;
        
        if (!$anon_session_id && !$visitor_token) {
            return;  // Nothing to merge
        }
        
        $client = new \Smaily\RecEngine\Api\EngineClient();
        $response = $client->post('/api/v1/identity/merge', [
            'anon_session_id' => $anon_session_id,
            'smaily_visitor_token' => $visitor_token,
            'customer_email' => $email,
            'merge_ts' => gmdate('c'),
            'merge_reason' => $reason,
        ]);
        
        // Don't surface errors to user — merge is best-effort
        if (is_wp_error($response)) {
            error_log('Smaily Rec identity merge failed: ' . $response->get_error_message());
        }
    }
}
```

---

## Error handling + retry

`src/Api/EngineClient.php`:

```php
<?php
namespace Smaily\RecEngine\Api;

use Smaily\RecEngine\Utils\ConfigStore;

class EngineClient {
    private array $config;
    
    public function __construct() {
        $this->config = (new ConfigStore())->get_connection();
    }
    
    public function post(string $endpoint, array $body, int $retry_count = 0) {
        if (!$this->config) {
            return new \WP_Error('not_connected', 'Plugin not connected to engine');
        }
        
        $url = $this->config['engine_base_url'] . $endpoint;
        
        $response = wp_remote_post($url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->config['api_key'],
                'User-Agent' => sprintf('SmailyRecEngine-WooPlugin/%s', SMLY_REC_VERSION),
            ],
            'body' => wp_json_encode($body),
            'timeout' => 30,
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $status = wp_remote_retrieve_response_code($response);
        $body_json = json_decode(wp_remote_retrieve_body($response), true);
        
        // Check engine version
        $engine_version = wp_remote_retrieve_header($response, 'x-engine-version');
        if ($engine_version && !$this->is_compatible_version($engine_version)) {
            // Log notice but don't refuse — graceful degradation
            error_log("Smaily Rec engine version mismatch: $engine_version (plugin supports 1.x)");
        }
        
        if ($status >= 200 && $status < 300) {
            return $body_json;
        }
        
        // Handle retry for 429 + 5xx
        if (($status === 429 || $status >= 500) && $retry_count < 5) {
            $retry_after = (int) wp_remote_retrieve_header($response, 'retry-after');
            $delay = max(1, $retry_after ?: pow(2, $retry_count));
            sleep(min($delay, 16));  // max 16s
            return $this->post($endpoint, $body, $retry_count + 1);
        }
        
        return new \WP_Error(
            $body_json['error'] ?? 'http_error',
            $body_json['message'] ?? "HTTP $status"
        );
    }
    
    public function get(string $endpoint, int $retry_count = 0) {
        // ... similar to post
    }
    
    public function delete(string $endpoint, array $body = [], int $retry_count = 0) {
        // ... similar to post
    }
    
    private function is_compatible_version(string $engine_version): bool {
        // Plugin supports engine v1.x
        return version_compare($engine_version, '1.0.0', '>=') 
            && version_compare($engine_version, '2.0.0', '<');
    }
}
```

---

## Test plan

**Unit tests** (PHPUnit):
- `SetupTokenExchange::exchange()` test (mock HTTP)
- `CatalogBatchJob::build_product_payload()` (mock WC_Product)
- `OrderReader::build_engine_payload()` (mock WC_Order, HPOS-aware)
- `BeaconEndpoint::handle_beacon()` (mock WP_REST_Request)
- `MultilingualResolver::get_product_translations()` (mock WPML/Polylang)

**Integration tests** (real-WP environment):
- Plugin activation flow
- Setup token exchange (real engine endpoint)
- Catalog sync after product save
- Order sync after order completion
- Identity merge after login
- GDPR export + delete flows

**E2E tests** (Cypress vol sarnane):
- Frontend beacon test: visit product page → check WP REST beacon endpoint received call → check Action Scheduler queued flush
- Email click flow: click test email link → cookie set → browse event includes visitor_token

---

## Lõpetuseks

See dokument + `API_CONTRACT_v1.0.md` annab plugin-agendile **kõik vajaliku** Etapp 3 (rec-engine integratsioon) ehitamiseks. 

Üks lahtine küsimus, mis vajab Erkki kinnitust:
- **POST /setup/exchange** (mu praegune valik) vs **GET /setup/{token}** (varasem soovitus). Plugin saab toetada mõlemat (settings UI accept'b nii URL-i kui ka token'it), aga eelistame **POST** turvalisemaks vooluks.

Plugin-agent: kui näed mu spec'is midagi, mis on WP/WC-spetsiifiliselt vale, palun tagasiside. Mootor-pool jätkab Faas 2.5 implementatsiooni paralleelselt.
