<?php
/** Regression: an expired update license must not be described as activated. */
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) { exit; }
$client = $GLOBALS['rcs_pro_updater'] ?? null;
if (!$client || !RemoteContentShortcodePro\Vendor\get_option('rcs_pro_de_token')) { WP_CLI::error('Activate a lab update license first.'); }
$original = RemoteContentShortcodePro\Vendor\get_option('rcs_pro_de_paid_through', null);
try {
    RemoteContentShortcodePro\Vendor\update_option('rcs_pro_de_paid_through', (int)(microtime(true) * 1000) - 1000, false);
    ob_start();
    $client->license_page();
    $html = ob_get_clean();
    if (!str_contains($html, 'Updates: Expired')) { throw new RuntimeException('Expired update license is still described as activated.'); }
    if (!class_exists('RCS_Pro_Sources')) { throw new RuntimeException('Installed Pro functionality must remain available.'); }
    WP_CLI::success('Expired update license is described accurately; installed Pro stays available.');
} finally {
    if ($original === null) { RemoteContentShortcodePro\Vendor\delete_option('rcs_pro_de_paid_through'); }
    else { RemoteContentShortcodePro\Vendor\update_option('rcs_pro_de_paid_through', $original, false); }
}
