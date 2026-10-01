=== CSSFlow ===
Tags: custom css, responsive css, css editor, woocommerce, developer tools
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage global, page, post, product, and responsive custom CSS from one organized WordPress interface.

== Description ==

CSSFlow helps you organize custom CSS inside WordPress without scattering styles across themes, page builders, or individual templates.

Create CSS snippets, choose where they should load, control responsive behavior, and manage your CSS from one WordPress admin area. CSSFlow keeps snippet data in WordPress and generates only the frontend CSS needed for matching pages and contexts.

Features include:

* Global CSS snippets.
* Page, post, and specific-content targeting.
* Post type and special WordPress page/context targeting.
* WooCommerce targeting for Shop, all products, specific products, product categories, Cart, Checkout, and My Account when WooCommerce is active.
* Desktop, tablet, mobile, and custom breakpoint targeting.
* Custom breakpoint management.
* Reusable global CSS variables with direct-color swatches for supported color values.
* All, Active, and Inactive snippet views with live counts.
* Fast Active/Inactive status toggles from the All CSS screen.
* Snippet priority, duplication, filtering, sorting, pagination, and bulk actions.
* WordPress enhanced CSS editor support with a normal textarea fallback.
* Optional CSSFlow setting to disable the enhanced editor while respecting the WordPress user syntax-highlighting preference.
* Advisory CSS Problems checks without blocking valid modern CSS from being saved.
* Generated CSS file output with automatic inline fallback when file output is unavailable.
* Safe Mode support through the `CSSFLOW_SAFE_MODE` constant.
* Full CSSFlow JSON backup and import tools.
* Compiled CSS download.
* WordPress Additional CSS detection and safe inactive import.
* Output health information, CSS regeneration tools, and Site Health integration.
* Dedicated Support screen with documentation guidance and support resources.
* Direct Manage CSS shortcut from the WordPress Plugins screen.
* Optional data removal on uninstall.

CSSFlow does not add frontend JavaScript and does not send telemetry or make remote tracking requests.

== Installation ==

1. Upload the `cssflow` folder to the `/wp-content/plugins/` directory, or install CSSFlow through the WordPress Plugins screen.
2. Activate CSSFlow through the Plugins screen in WordPress.
3. Click **Manage CSS** on the Plugins screen, or open **CSSFlow > All CSS**.
4. Use **CSSFlow > Add CSS** to create your first CSS snippet.
5. Use **Breakpoints**, **Variables**, **Tools**, **Settings**, and **Support** as needed.

== Screenshots ==

1. All CSS — manage snippets, search and filter CSS, switch Active/Inactive status, review targeting and responsive settings, and use pagination.
2. Edit CSS — edit CSS with the enhanced editor and control status, targeting, responsive behavior, and priority from one screen.
3. Breakpoints — manage the built-in responsive ranges and create reusable custom breakpoints.
4. Variables — create and manage reusable global CSS variables, including visual swatches for supported direct color values.
5. Tools — review CSS output health, regenerate generated CSS, back up CSSFlow, download compiled CSS, and use import/migration tools.
6. Settings — choose generated-file or inline output, control uninstall data removal, and enable or disable the enhanced code editor.
7. Support — find setup guidance, CSSFlow support, security contact information, and developer resources.

== Frequently Asked Questions ==

= Does CSSFlow modify my theme files? =

No. CSSFlow stores its own snippet configuration and outputs generated CSS without editing your theme files.

= Can I target individual pages, posts, or products? =

Yes. CSSFlow supports specific-content targeting along with broader WordPress and WooCommerce contexts.

= Does CSSFlow work without WooCommerce? =

Yes. WooCommerce-specific targeting is available only when WooCommerce is active; the rest of CSSFlow works independently.

= Can I use CSSFlow without the enhanced code editor? =

Yes. You can disable the enhanced editor in CSSFlow Settings, and CSSFlow also respects the WordPress user preference for syntax highlighting. A normal textarea remains available as the fallback editor.

= What happens if generated CSS files cannot be written? =

CSSFlow can fall back to inline output so saved CSS can continue to load when file output is unavailable.

= Does deactivating CSSFlow delete my data? =

No. Deactivation preserves CSSFlow data. Destructive cleanup on uninstall occurs only when the delete-on-uninstall setting has been explicitly enabled.

= Does CSSFlow add JavaScript to the frontend? =

No. CSSFlow does not require or enqueue frontend JavaScript.

= Does CSSFlow send usage data or telemetry? =

No. CSSFlow does not include telemetry or remote tracking calls.

== Changelog ==

= 1.0.0 =
* Initial release.
* Added CSS snippet management with All, Active, and Inactive views.
* Added WordPress and WooCommerce targeting.
* Added responsive targeting, built-in breakpoints, and custom breakpoint management.
* Added reusable global CSS variables.
* Added enhanced CSS editor support with textarea fallback and advisory CSS Problems checks.
* Added generated-file and inline CSS output with automatic fallback and Safe Mode support.
* Added JSON backup/import, compiled CSS download, and WordPress Additional CSS import tools.
* Added output health, CSS regeneration, Site Health integration, Settings, and Support screens.
* Added accessibility, RTL, compatibility, security, and WordPress.org release-readiness improvements.
