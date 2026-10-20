(function (wp) {
    'use strict';
    var el = wp.element.createElement, __ = wp.i18n.__, c = wp.components;
    var ServerSideRender = wp.serverSideRender.ServerSideRender || wp.serverSideRender.default || wp.serverSideRender;
    var name = 'remote-content-shortcode/remote-content';
    wp.blocks.registerBlockType(name, {
        edit: function (props) {
            var a = props.attributes;
            function text(key, label, help) {
                return el(c.TextControl, { key: key, label: label, help: help || undefined, value: a[key], onChange: function (v) { if (key === 'userpwd' && !/^[A-Za-z0-9_.-]*$/.test(v)) { return; } var next = {}; next[key] = v; props.setAttributes(next); } });
            }
            function number(key, label, min, max) {
                return el(c.TextControl, { key: key, label: label, type: 'number', min: min, max: max, value: a[key], onChange: function (v) { var next = {}; next[key] = Math.max(min, Math.min(max, Number(v) || 0)); props.setAttributes(next); } });
            }
            function toggle(key, label) {
                return el(c.ToggleControl, { key: key, label: label, checked: a[key], onChange: function (v) { var next = {}; next[key] = v; props.setAttributes(next); } });
            }
            return el('div', wp.blockEditor.useBlockProps(),
                el(wp.blockEditor.InspectorControls, {}, el(c.PanelBody, { title: __('Remote content settings', 'remote-content-shortcode') },
                    text('url', __('Source URL', 'remote-content-shortcode')), text('selector', __('CSS selector', 'remote-content-shortcode')), text('remove', __('Remove selector', 'remote-content-shortcode')),
                    text('userpwd', __('Credential alias', 'remote-content-shortcode'), __('Use a configured alias. Never enter a username or password.', 'remote-content-shortcode')),
                    text('fallback', __('Fallback HTML', 'remote-content-shortcode')), toggle('cache', __('Cache response', 'remote-content-shortcode')),
                    number('cacheTtl', __('Cache lifetime (seconds)', 'remote-content-shortcode'), 1, 86400), number('staleTtl', __('Stale lifetime (seconds)', 'remote-content-shortcode'), 0, 86400),
                    toggle('rewriteUrls', __('Rewrite relative URLs', 'remote-content-shortcode')), number('timeout', __('Timeout (seconds)', 'remote-content-shortcode'), 1, 30))),
                a.url ? el(ServerSideRender, { block: name, attributes: a, httpMethod: 'POST' }) : el(c.Placeholder, { label: __('Remote content', 'remote-content-shortcode') }, __('Set a source URL in the block settings.', 'remote-content-shortcode')));
        },
        save: function () { return null; }
    });
}(window.wp));
