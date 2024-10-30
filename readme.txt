=== Plugin Name ===
Contributors: evilkitteh
Tags: analysis, code, malware, plugins, privacy, regex, security, scanner, search
Requires at least: 3.0
Tested up to: 4.3
Stable tag: 0.2
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Simple search tool using regular expressions to find unwanted code in plugins.

== Description ==

Scans plugin files for matches to **custom regex patterns**. Useful for checking whether your plugins don't do anything shady.

= Default search patterns match the following: =
* Exploitable PHP and JS functions and HTML tags
* Code (de)obfuscation
* Remote requests (including pingbacks, trackbacks and mail sending)
* Filesystem modification
* Direct database queries
* User creation
* Inline and enqueued scripts
* Unicode and ASCII character literals, integer literals
* URL addresses
* Strings containing "swf"
* Google Analytics and AdSense IDs

== Installation ==

1. Install the plugin.
2. Go to Settings > Code Analyzer to configure the plugin.
3. To analyze a plugin, click the appropriate "Analyze code" link on the Plugins page.

== Screenshots ==

1. Configuration page
2. Example code analysis: Akismet

== Changelog ==

= 0.2 =

* Results are now sorted alphabetically
* New option "Results display mode"
* New search pattern "User creation"
