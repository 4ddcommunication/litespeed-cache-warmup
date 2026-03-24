<?php
/**
 * Plugin Name: LiteSpeed Cache Warmup Dashboard
 * Plugin URI:  https://github.com/4ddcommunication/litespeed-cache-warmup
 * Description: WordPress Dashboard for LiteSpeed Cache Warmup — live progress, manual trigger, log viewer.
 * Version:     1.0.0
 * Author:      4dd communication
 * Author URI:  https://4dd.de
 * License:     MIT
 *
 * Installation: Copy to wp-content/mu-plugins/ for auto-activation.
 *
 * Configuration: Define these constants in wp-config.php (all optional):
 *   define('CWU_WARMUP_SCRIPT', '/path/to/cache-warmup.sh');
 *   define('CWU_LOG_FILE', '/var/log/cache-warmup.log');
 *
 * The status file is always read from wp-content/cache-warmup-status.json.
 */

defined('ABSPATH') || exit;

add_action('admin_menu', function() {
    add_menu_page(
        'Cache Warmup',
        'Cache Warmup',
        'manage_options',
        'cache-warmup',
        'cwu_dashboard_page',
        'dashicons-performance',
        80
    );
});

add_action('wp_ajax_cwu_start_warmup', function() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized'], 403);
    }

    $script = defined('CWU_WARMUP_SCRIPT')
        ? CWU_WARMUP_SCRIPT
        : dirname(ABSPATH) . '/cache-warmup.sh';

    if (!file_exists($script)) {
        wp_send_json_error(['message' => "Script not found: $script"]);
    }

    exec("bash " . escapeshellarg($script) . " > /dev/null 2>&1 &");
    wp_send_json_success(['message' => 'Warmup gestartet']);
});

add_action('wp_ajax_cwu_get_status', function() {
    $file = WP_CONTENT_DIR . '/cache-warmup-status.json';
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
        if (is_array($data)) {
            $data['age'] = time() - filemtime($file);
            wp_send_json_success($data);
        }
    }
    wp_send_json_error(['message' => 'Kein Status vorhanden']);
});

add_action('wp_ajax_cwu_get_log', function() {
    $log = defined('CWU_LOG_FILE')
        ? CWU_LOG_FILE
        : '/var/log/cache-warmup.log';

    if (file_exists($log) && is_readable($log)) {
        $lines = array_slice(file($log), -20);
        wp_send_json_success(['lines' => array_map('trim', $lines)]);
    }
    wp_send_json_error(['message' => 'Kein Log vorhanden']);
});

function cwu_dashboard_page() {
?>
<div class="wrap">
    <h1 style="display:flex;align-items:center;gap:8px;">
        <span class="dashicons dashicons-performance" style="font-size:28px;"></span>
        Cache Warmup
    </h1>

    <div id="cwu-status-card" style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:24px;max-width:620px;margin:20px 0;box-shadow:0 1px 1px rgba(0,0,0,.04);">
        <h2 style="margin-top:0;">Aktueller Status</h2>
        <div id="cwu-status" style="font-size:15px;">Lade...</div>
        <div id="cwu-progress-bar" style="display:none;background:#f0f0f0;border-radius:4px;height:28px;margin:16px 0;overflow:hidden;">
            <div id="cwu-progress-fill" style="background:#2271b1;height:100%;border-radius:4px;transition:width 0.5s;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:bold;font-size:13px;min-width:40px;"></div>
        </div>
        <div id="cwu-details" style="display:none;margin:16px 0;">
            <table class="widefat striped" style="max-width:400px;">
                <tr><td><strong>Seiten gesamt</strong></td><td id="cwu-total">-</td></tr>
                <tr><td><strong>Bereits im Cache</strong></td><td id="cwu-hits" style="color:#00a32a;">-</td></tr>
                <tr><td><strong>Neu gecacht</strong></td><td id="cwu-misses" style="color:#2271b1;">-</td></tr>
                <tr><td><strong>Fehler</strong></td><td id="cwu-errors" style="color:#d63638;">-</td></tr>
                <tr><td><strong>Dauer</strong></td><td id="cwu-duration">-</td></tr>
            </table>
        </div>
        <p style="margin-bottom:0;">
            <button id="cwu-start" class="button button-primary button-large" style="margin-top:8px;">
                Warmup jetzt starten
            </button>
            <span id="cwu-started-msg" style="display:none;color:#00a32a;margin-left:12px;font-weight:bold;">
                Gestartet!
            </span>
        </p>
    </div>

    <div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:24px;max-width:620px;margin:20px 0;box-shadow:0 1px 1px rgba(0,0,0,.04);">
        <h2 style="margin-top:0;">Log</h2>
        <pre id="cwu-log" style="background:#1d2327;color:#50c878;padding:16px;border-radius:4px;font-family:monospace;font-size:12px;max-height:300px;overflow-y:auto;line-height:1.6;">Lade...</pre>
    </div>
</div>

<script>
(function($){
    var polling = null;

    function loadStatus() {
        $.post(ajaxurl, {action:'cwu_get_status'}, function(r) {
            if (!r.success) {
                $('#cwu-status').html('<span style="color:#666;">Noch kein Warmup durchgefuehrt</span>');
                return;
            }
            var d = r.data, bar = $('#cwu-progress-bar'), fill = $('#cwu-progress-fill'), det = $('#cwu-details');
            det.show();
            $('#cwu-total').text(d.total || '-');
            $('#cwu-hits').text(d.hits || '0');
            $('#cwu-misses').text(d.misses || '0');
            $('#cwu-errors').text(d.errors || '0');

            if (d.status === 'running') {
                var p = d.percent || 0;
                $('#cwu-status').html('<span style="color:#2271b1;font-weight:bold;">Laeuft... ' + p + '% (' + (d.current||0) + '/' + (d.total||0) + ') &mdash; noch ca. ' + (d.eta_min||'?') + ' Min.</span>');
                bar.show(); fill.css('width', p+'%').text(p+'%').css('background','#2271b1');
                if (!polling) polling = setInterval(loadStatus, 3000);
            } else if (d.status === 'done') {
                var h = d.age ? Math.round(d.age/3600) : 0;
                var t = h < 1 ? 'gerade eben' : 'vor '+h+'h';
                $('#cwu-status').html('<span style="color:#00a32a;font-weight:bold;">Fertig</span> &mdash; ' + t + (d.finished ? ' (' + d.finished + ')' : ''));
                bar.show(); fill.css('width','100%').text('100%').css('background','#00a32a');
                $('#cwu-duration').text((d.duration_min||0) + ' Min.');
                if (polling) { clearInterval(polling); polling = null; }
            } else if (d.status === 'collecting') {
                $('#cwu-status').html('<span style="color:#dba617;font-weight:bold;">Sammle URLs aus Sitemaps...</span>');
                if (!polling) polling = setInterval(loadStatus, 3000);
            } else if (d.status === 'error') {
                $('#cwu-status').html('<span style="color:#d63638;font-weight:bold;">Fehler: ' + (d.message||'Unbekannt') + '</span>');
                if (polling) { clearInterval(polling); polling = null; }
            }
        });
    }

    function loadLog() {
        $.post(ajaxurl, {action:'cwu_get_log'}, function(r) {
            $('#cwu-log').text(r.success ? r.data.lines.join('\n') : 'Noch keine Log-Eintraege');
        });
    }

    $('#cwu-start').on('click', function() {
        var btn = $(this);
        btn.prop('disabled',true).text('Wird gestartet...');
        $.post(ajaxurl, {action:'cwu_start_warmup'}, function(r) {
            btn.prop('disabled',false).text('Warmup jetzt starten');
            if (r.success) {
                $('#cwu-started-msg').show().delay(3000).fadeOut();
                setTimeout(loadStatus, 2000);
                if (!polling) polling = setInterval(loadStatus, 3000);
            } else {
                alert('Fehler: ' + (r.data && r.data.message ? r.data.message : 'Unbekannt'));
            }
        });
    });

    loadStatus(); loadLog();
    setInterval(loadLog, 15000);
})(jQuery);
</script>
<?php
}
