<?php
/** Reusable examples in actual published WordPress posts. No HTTP interception. */
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) { exit; }
$mode = $args[0] ?? 'check';
function rcs_lab_post($slug, $title, $content) {
    $existing = get_page_by_path($slug, OBJECT, 'post');
    $result = wp_insert_post(['ID' => $existing ? $existing->ID : 0, 'post_name' => $slug,
        'post_title' => $title, 'post_content' => $content, 'post_status' => 'publish', 'post_type' => 'post'], true);
    if (is_wp_error($result)) { WP_CLI::error('Could not save fixture post.'); }
    return $result;
}
if (!class_exists('RemoteContentShortcode')) { WP_CLI::error('Activate Free first.'); }
if ($mode === 'seed') {
    rcs_lab_post('free-shortcode', 'Free: selected remote content', '<!-- wp:paragraph --><p>This heading comes from the Remote Content product site using the Free shortcode.</p><!-- /wp:paragraph --><!-- wp:shortcode -->[remote_content url="https://remote-content-shortcode.doublesharp.com/" selector="h1" cache_ttl="60" fallback="Remote source unavailable"]<!-- /wp:shortcode -->');
    rcs_lab_post('free-block', 'Free: Remote content block', '<!-- wp:remote-content-shortcode/remote-content {"url":"https://remote-content-shortcode.doublesharp.com/","selector":"h1","fallback":"Remote source unavailable"} /-->');
    rcs_lab_post('free-fallback', 'Free: blocked destination fallback', '<!-- wp:shortcode -->[remote_content url="http://127.0.0.1/private" fallback="Private destination safely refused"]<!-- /wp:shortcode -->');
    if (class_exists('RCS_Pro_Sources')) {
        $id = get_option('rcs_lab_source_id');
        if (!$id || !RCS_Pro_Sources::configuration($id)) {
            $id = RCS_Pro_Sources::save(['name' => 'Product site heading', 'attributes' => ['url' => 'https://remote-content-shortcode.doublesharp.com/', 'selector' => 'h1', 'cache_ttl' => 60, 'stale_ttl' => 3600, 'fallback' => 'Remote source unavailable'], 'interval' => 300]);
            if (is_wp_error($id)) { WP_CLI::error('Could not create Pro source: ' . $id->get_error_code()); }
            update_option('rcs_lab_source_id', $id, false);
        }
        rcs_lab_post('pro-source', 'Pro: reusable scheduled source', '<!-- wp:shortcode -->[remote_content_source id="' . esc_attr($id) . '"]<!-- /wp:shortcode -->');
    }
    WP_CLI::success('Example posts saved.');
    return;
}
$rendered = do_shortcode('[remote_content url="https://remote-content-shortcode.doublesharp.com/" selector="h1" cache="false"]');
if (!str_contains($rendered, 'Less copy-paste.')) { WP_CLI::error('Free live source did not render.'); }
$fallback = do_shortcode('[remote_content url="http://127.0.0.1/private" fallback="Private destination safely refused"]');
if ($fallback !== 'Private destination safely refused') { WP_CLI::error('Private destination fallback failed.'); }
if (class_exists('RCS_Pro_Sources')) {
    $id = get_option('rcs_lab_source_id');
    $refreshed = $id ? RCS_Pro_Sources::refresh($id) : false;
    if (!$refreshed || is_wp_error($refreshed) || !str_contains(RCS_Pro_Sources::render($id), 'Less copy-paste.')) { WP_CLI::error('Pro source did not refresh/render.'); }
    if (!wp_next_scheduled('rcs_pro_refresh_source', [$id])) { WP_CLI::error('Pro source schedule is missing.'); }
    WP_CLI::success('Pro saved source, encrypted snapshot, rendering and schedule work.');
}
WP_CLI::success('Free live HTML selection and private-destination fallback work.');
