# CSSFlow

**Responsive custom CSS management for WordPress.**

CSSFlow is a free WordPress plugin for managing custom CSS across global, page, post, WooCommerce, and responsive contexts from a clean WordPress-native interface.

**Version:** `1.0.0`

**Current status:** Ready for WordPress.org submission.

- Website: https://qasim-wordpress-developer.com/cssflow/
- Documentation: https://qasim-wordpress-developer.com/cssflow/documentation/

## Features

- Global custom CSS
- Page and post targeting
- Custom post type targeting
- WordPress special-page targeting
- WooCommerce targeting
- Mobile, tablet and desktop targeting
- Reusable custom breakpoints
- CSS variables
- Snippet priorities
- Active / inactive snippet management
- JSON backup and restore
- CSS export
- Generated CSS file output
- Inline CSS output
- Output regeneration and health checks
- Safe Mode
- WordPress-native enhanced code editor support

## WooCommerce

WooCommerce is optional.

When WooCommerce is active, CSSFlow can target supported store contexts including:

- Shop
- Products
- Specific products
- Product categories
- Cart
- Checkout
- My Account

## Requirements

- WordPress 6.4 or newer
- PHP 7.4 or newer
- WooCommerce only when WooCommerce-specific targeting is required

## Privacy

CSSFlow does not include telemetry, hidden tracking, advertising, or automatic remote data collection.

The plugin does not require an external service to manage or deliver CSS.

## Output

CSSFlow supports two frontend output methods:

- Generated CSS files
- Inline CSS

Generated CSS files are treated as disposable output. CSSFlow's stored snippets and settings remain the source of truth.

## Documentation

Complete documentation is available at:

https://qasim-wordpress-developer.com/cssflow/documentation/

The documentation covers:

- Getting started
- CSS snippets
- Targeting
- WooCommerce
- Responsive CSS
- Breakpoints
- CSS variables
- Tools
- Settings
- Safe Mode
- Troubleshooting
- Developer hooks

## Development

This repository contains the public CSSFlow source code and release documentation.

The distributable WordPress plugin is contained in:

```text
cssflow/