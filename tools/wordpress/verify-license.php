<?php
/** Live Entitle checks. Credentials and provider bodies are never printed. */
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) { exit; }
$client = $GLOBALS['rcs_pro_updater'] ?? null;
if (!$client) { WP_CLI::error('Activate Pro first.'); }
$mode = $args[0] ?? 'offer';
if ($mode === 'offer') {
    $metadata = $client->metadata(true);
    if (!$metadata || $metadata['version'] !== ($args[1] ?? '2.0.2')) { WP_CLI::error('Expected live release is not offered.'); }
    update_option('rcs_lab_cached_offer', $metadata, false);
    wp_update_plugins();
    WP_CLI::success('Live update offer cached for ' . $metadata['version'] . '.');
    return;
}
if ($mode !== 'expired') { WP_CLI::error('Use offer VERSION or expired.'); }
$offer = get_option('rcs_lab_cached_offer');
if (!$offer || !str_starts_with($offer['download_url'], $client->api_base() . '/packages/')) { WP_CLI::error('Cache a live offer first.'); }
$reply = wp_remote_get($offer['download_url'], ['headers' => ['Authorization' => 'Bearer ' . RemoteContentShortcodePro\Vendor\get_option('rcs_pro_de_token')], 'redirection' => 0, 'timeout' => 20]);
if (is_wp_error($reply) || wp_remote_retrieve_response_code($reply) !== 403) { WP_CLI::error('Expired cached-package access was not denied with HTTP 403.'); }
if ($client->metadata(true) !== false) { WP_CLI::error('Expired license still receives update metadata.'); }
$transient = $client->updates((object)['checked' => [plugin_basename(RCS_PRO_FILE) => RCS_PRO_VERSION]]);
if (!empty($transient->response[plugin_basename(RCS_PRO_FILE)])) { WP_CLI::error('Denied update offer was not removed.'); }
if (!class_exists('RCS_Pro_Sources') || !shortcode_exists('remote_content_source')) { WP_CLI::error('Installed Pro features disappeared.'); }
WP_CLI::success('Expired license: cached package HTTP 403, update metadata denied, update offer removed, installed Pro still loaded.');
