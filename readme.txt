=== Standard Consent ===
Contributors: peterkim
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 8.2
Stable tag: 0.2.6
License: Proprietary

A cookie-consent banner and preferences manager that gates trackers until the visitor opts in. A lightweight, in-house replacement for CookieYes / Complianz.

== Description ==

A cookie-consent banner and preferences manager that gates trackers until the visitor opts in.

Standard Consent is a self-contained, in-house alternative to CookieYes / Complianz. It stores its data in its own
tables and ships with everything it needs — there is nothing else to install.

== Installation ==

1. In your WordPress admin, go to **Plugins → Add New → Upload Plugin**.
2. Choose the **pk-standard-consent-0.2.0.zip** file and click **Install Now**.
3. Click **Activate**.

That's it. The plugin creates everything it needs on activation; there is no separate setup step
required to start using it. You'll find it in the admin menu after activating.



== Frequently Asked Questions ==

= Do I need to run a build or install dependencies? =

No. The zip is a finished, ready-to-run plugin. Just upload and activate.

= How do I remove it cleanly? =

Deactivate and delete it from the Plugins screen. If you turned on "remove all data on uninstall"
in the plugin's settings, deleting it also drops its tables and options; otherwise your data is
left in place so you can reactivate later.

== Changelog ==

= 0.2.0 =
* Current release.
