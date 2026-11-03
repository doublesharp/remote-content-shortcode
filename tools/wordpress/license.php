<?php
/** Activate with explicit consent. Key arrives on stdin and is never printed. */
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) { exit; }
$client = $GLOBALS['rcs_pro_updater'] ?? null;
if (!$client) { WP_CLI::error('Activate Pro first.'); }
$endpoint = $args[0] ?? '';
if (!str_starts_with($endpoint, 'https://entitle.doublesharp.com/api/v1/tenants/')) {
    WP_CLI::error('Use the hosted Entitle tenant API.');
}
if ($client->api_base() !== $endpoint) {
    $result = $client->set_endpoint($endpoint);
    if (is_wp_error($result)) { WP_CLI::error($result->get_error_message()); }
}
$result = $client->activate(trim(fgets(STDIN)), true);
if (is_wp_error($result)) { WP_CLI::error($result->get_error_code() . ': ' . $result->get_error_message()); }
WP_CLI::success('Pro update license activated with consent. The raw key was not stored in WordPress.');
