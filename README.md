[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](https://github.com/solution25com/curbstone-payment-shopware-6-solution25/blob/main/LICENSE)

# Curbstone Payments for Shopware 6

## Introduction

The **Curbstone Payments Plugin** integrates the [Curbstone](https://www.curbstone.com) payment gateway into your Shopware 6 store, enabling secure credit card processing with full PCI-aware UX patterns designed for high-conversion checkouts.

The plugin supports two checkout integration modes — **PLP** (Payment Landing Page) and **DSI** (Direct Server Integration) — along with authorize-only and immediate capture flows, saved card management, automatic refunds and voids, and a configurable high-value order deferral system for ERP-based manual authorization. All gateway communication is handled server-side, keeping sensitive card data off your servers.

---

## Key Features

### Credit Card Processing
- Accepts credit card payments via the **Curbstone PLP or DSI integration**, redirecting card data handling to Curbstone's PCI-compliant environment.

### Authorize & Capture Flow
- Choose between **Authorize Only** (capture later, e.g. via ERP) or **Authorize & Capture** (immediate charge) per Sales Channel.

### Inline Pre-Authorization (0.00 Auth)
- On the checkout confirmation page, a **Curbstone-hosted iframe** collects and tokenizes card details via a zero-amount pre-authorization, keeping card data entirely off your servers before the order is placed.

### Saved Cards (Vault)
- Customers can **save cards** during checkout for faster repeat purchases. Saved cards are stored as vaulted tokens (MFKEYP) against the customer's account and can be managed from the **Account > Saved Cards** page.

### High-Value Order Deferral
- Orders exceeding a configurable **high-value threshold** are automatically deferred for manual ERP authorization instead of being charged immediately. The transaction is flagged as `pending_erp` and the card token is stored securely on the order for later use.

### Automatic Refunds
- When an order transaction is set to **Refunded** in Shopware, the plugin automatically sends a refund request to Curbstone and stores the refund metadata on the transaction.

### Void Authorization
- When a payment is cancelled before capture, the plugin sends a **void request** to Curbstone to release the authorized funds.

### Dual Integration Modes
- **PLP mode**: Uses Curbstone's hosted Payment Landing Page. Supports `embedded` and `redirect` sub-modes.
- **DSI mode**: Direct Server Integration using a merchant DSI key for tighter control.

### Multi-Environment Support
- Switch between **Sandbox** (`c3sbx.net`) and **Live** (`c3plp.net` / `c3dsi.net`) environments from the Admin config — no code changes required. Sandbox and production endpoints are automatically resolved based on your mode selection.

### Retry Logic
- Configurable **retry count and backoff interval** for resilience against transient API failures.

### TLS Verification
- TLS certificate validation is enforced by default. Can be overridden via Admin toggle or the `CURBSTONE_HTTP_VERIFY` environment variable for controlled environments.

### Transaction Metadata
- All gateway request and response payloads (sanitized of sensitive data) are stored as **order custom fields** for full traceability in the Admin order view.

### Comprehensive Logging
- Detailed logging of all payment, refund, void, and pre-authorization events for troubleshooting and audit purposes.

---

## Compatibility
- ✅ Shopware 6.7.x
- ✅ PHP 8.1+

---

## Get Started

### Installation & Activation

#### GitHub

1. Clone the plugin into your Shopware plugins directory:

```bash
git clone https://github.com/solution25com/curbstone-payment-shopware-6-solution25.git
```

2. **Install the Plugin in Shopware 6**

   - Log in to your Shopware 6 Administration panel.
   - Navigate to **Extensions > My Extensions**.
   - Locate the plugin and click **Install**.

3. **Activate the Plugin**

   - After installation, click **Activate** to enable the plugin.
   - Run the following commands from your Shopware root:

```bash
bin/console plugin:refresh
bin/console plugin:install --activate Curbstone
bin/console cache:clear
```

4. **Build Storefront Assets**

```bash
bin/console bundle:dump
bin/build-storefront.sh
bin/console cache:clear
```

5. **Verify Installation**

   - After activation, you will see **Curbstone Payments** in the list of installed plugins.
   - The plugin name, version, and installation date should appear.

---

## Plugin Configuration

After installing the plugin, configure your **Curbstone** credentials and options through the Shopware Administration panel.

### Accessing the Configuration

1. Go to **Settings > Curbstone Payments**
2. Select the **Sales Channel** you want to configure
3. Set the following fields:

### General Settings

| Field | Description |
|---|---|
| **Sandbox Mode** | Enable to use Curbstone sandbox endpoints (`c3sbx.net`). Disable for production (`c3plp.net` / `c3dsi.net`) |
| **Disable Curbstone Subscribers** | When enabled, Curbstone event subscribers (refund, void) will not execute for this Sales Channel |
| **Verify TLS Certificates** | Validates the Curbstone server certificate on all HTTPS calls (recommended). Can also be controlled via the `CURBSTONE_HTTP_VERIFY` environment variable |

### API Credentials

| Field | Description |
|---|---|
| **Merchant DSI Key (MFDSIK)** | Your Curbstone Merchant DSI key — required when using DSI checkout integration |
| **Customer ID (MFCUST)** | Your Curbstone Customer ID |
| **Merchant Code (MFMRCH)** | Your Curbstone Merchant Code |

### Payment Settings

| Field | Description |
|---|---|
| **Auth / Capture Flow** | `Auth Only` — authorizes the card and defers capture (e.g. for ERP). `Auth & Capture` — authorizes and captures immediately |
| **PLP Mode** | How the PLP checkout is presented: `Embedded` (inline iframe) or `Redirect` (full-page redirect) |
| **Checkout Integration** | `PLP` — Curbstone hosted Payment Landing Page. `DSI` — Direct Server Integration (requires MFDSIK, MFCUST, MFMRCH) |

> **Note:** When using DSI integration, all three DSI credentials (MFDSIK, MFCUST, MFMRCH) are required. The plugin will throw a configuration error if any are missing.

### Advanced Settings

The following settings can be configured via Shopware system config or environment variables:

| Setting | Description | Default |
|---|---|---|
| `Curbstone.config.retries` | Number of retries on transient API failures | `2` |
| `Curbstone.config.backoffMs` | Backoff interval between retries in milliseconds | `120` |
| `Curbstone.config.highValueThreshold` | Orders above this amount (in store currency) are deferred for manual ERP authorization instead of being charged immediately | `5000.00` |
| `CURBSTONE_HIGH_VALUE_THRESHOLD` | Environment variable override for the high-value threshold | — |

---

## How It Works

### 1. Card Pre-Authorization at Checkout

On the checkout confirmation page, a **Curbstone-hosted iframe** is rendered inside the payment section. The customer enters their card details directly into Curbstone's secure environment. A zero-amount pre-authorization is sent to Curbstone, which returns a **payment token (MFKEYP)** that is stored in the session — no raw card data ever touches your server.

### 2. Payment on Order Placement

When the customer submits the order, Shopware triggers the payment handler. The plugin resolves the MFKEYP token (either from the session pre-auth or from a selected saved card) and sends a **real charge request** to Curbstone's PLP or DSI endpoint with the full order amount and billing details.

### 3. High-Value Order Deferral

If the order total exceeds the configured **high-value threshold**, no charge is sent immediately. Instead, the token and order metadata are saved to the transaction's custom fields with a `pending_erp` status, and the transaction is set to `in_progress` in Shopware. The charge is then expected to be triggered externally via ERP or manual authorization.

### 4. Saved Cards

If the customer checks **Save card** during checkout and the payment succeeds, Curbstone returns a vaulted card token (MFUKEY/MFKEYP) along with the card's last 4 digits, brand, and expiry. This is stored against the customer's Shopware account. On future checkouts, the customer can select a saved card from a paginated grid — bypassing the iframe entirely.

### 5. Refunds

When an order transaction is moved to **Refunded** in Shopware, the plugin reads the stored `MFSESS` and `MFKEYP` from the transaction's custom fields and sends a refund request to Curbstone. The refund response is stored back on the transaction for audit purposes.

### 6. Void

When a transaction is cancelled before capture, the plugin sends a **void request** to Curbstone to release the authorization hold. The void response is stored on the transaction custom fields.

---

## Saved Cards — Customer Account

Customers can manage their vaulted cards via **Account > Saved Cards** in the storefront. From this page they can:

- View all saved cards (brand, last 4 digits, expiry)
- Delete individual saved cards
- Select a saved card at checkout without re-entering card details

---

## Uninstallation

```bash
bin/console plugin:deactivate Curbstone
bin/console plugin:uninstall Curbstone
bin/console cache:clear
```

---

## License

MIT — see [LICENSE](LICENSE) for details.

---

## Support

For questions or issues, please open a [GitHub Issue](https://github.com/solution25com/curbstone-payment-shopware-6-solution25/issues) or contact [Solution25](https://solution25.com).
