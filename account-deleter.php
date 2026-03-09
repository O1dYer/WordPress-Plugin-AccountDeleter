<?php
/*
Plugin Name: 简易账号注销插件
Description: 在任何页面（文章页以外）插入代码 [delete_account] 即可使用。支持设置注销冷静期、后台管理。
Version: 26.3.9
Author: 欧叶
Author URI: https://github.com/O1dYer
Text Domain: account-deleter
*/

if (!defined('ABSPATH')) exit;

/**
 * 1. 插件安装与定时任务
 */
register_activation_hook(__FILE__, 'wad_account_deletion_activate');
function wad_account_deletion_activate() {
    if (!wp_next_scheduled('wp_account_deletion_cron')) {
        wp_schedule_event(time(), 'hourly', 'wp_account_deletion_cron');
    }
}

register_deactivation_hook(__FILE__, 'wad_account_deletion_deactivate');
function wad_account_deletion_deactivate() {
    wp_clear_scheduled_hook('wp_account_deletion_cron');
}

/**
 * 2. 前端短代码功能
 */
add_shortcode('delete_account', 'wad_delete_account_shortcode');
function wad_delete_account_shortcode() {
    if (!is_page()) {
        return '[delete_account]';
    }

    $error_box_style = 'style="color:#d63638; font-weight:bold; padding:15px; background:#fff5f5; border-left:4px solid #d63638; border-radius:4px; margin-bottom:20px; font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;"';

    if (!is_user_logged_in()) {
        return '<p ' . $error_box_style . '>' . __('请先登录再执行注销账号操作。', 'account-deleter') . '</p>';
    }
    
    $user = wp_get_current_user();
    if (in_array('administrator', $user->roles)) {
        return '<p ' . $error_box_style . '>' . __('为了保障安全，管理员账号不能从前端注销。', 'account-deleter') . '</p>';
    }

    $days = get_option('wad_deletion_days', 7);
    $notice = get_option('wad_deletion_notice', '若申请注销，您的账号将在 {days} 天后被永久删除，请知悉。');
    $notice = str_replace('{days}', $days, $notice);

    // 生成安全 Nonce
    $nonce = wp_create_nonce('wad_request_deletion_nonce');

    ob_start();
    ?>
    <style>
        .wad-modern-card { background: #ffffff; border: 1px solid #eaeaea; border-radius: 12px; padding: 24px; max-width: 500px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .wad-modern-card p { color: #4a5568; font-size: 15px; line-height: 1.6; margin-bottom: 20px; }
        .wad-modern-btn { background-color: #ffffff; color: #e53e3e; border: 1.5px solid #e53e3e; padding: 10px 20px; border-radius: 8px; font-weight: 600; font-size: 14px; cursor: pointer; transition: all 0.2s ease; display: inline-block; }
        .wad-modern-btn:hover { background-color: #fff5f5; transform: translateY(-1px); }
    </style>

    <div class="wad-modern-card">
        <p><?php echo nl2br(esc_html($notice)); ?></p>
        <button id="wad-delete-btn" class="wad-modern-btn"><?php _e('申请注销账号', 'account-deleter'); ?></button>
    </div>

    <script>
    (function() {
        const btn = document.getElementById('wad-delete-btn');
        if(!btn) return;
        btn.addEventListener('click', function() {
            const days = <?php echo intval($days); ?>;
            if (confirm('<?php _e('确定要申请注销吗？\n您的账号将在 ', 'account-deleter'); ?>' + days + ' <?php _e(' 天后被永久删除。在此期间重新登录可撤销申请。\n点击确定后将自动退出登录。', 'account-deleter'); ?>')) {
                fetch('<?php echo admin_url('admin-ajax.php'); ?>?action=wad_request_deletion&_ajax_nonce=<?php echo $nonce; ?>')
                .then(response => {
                    alert('<?php _e('申请成功，您的账号已退出登录并进入冷静期。', 'account-deleter'); ?>');
                    window.location.href = '<?php echo home_url(); ?>';
                })
                .catch(err => {
                    alert('<?php _e('请求处理失败，请稍后重试。', 'account-deleter'); ?>');
                });
            }
        });
    })();
    </script>
    <?php
    return ob_get_clean();
}

/**
 * 3. 处理注销申请的 AJAX 逻辑
 */
add_action('wp_ajax_wad_request_deletion', 'wad_handle_request_deletion');
function wad_handle_request_deletion() {
    check_ajax_referer('wad_request_deletion_nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error('Unauthorized');
    }

    $user_id = get_current_user_id();
    if (current_user_can('administrator')) {
        wp_send_json_error('Admin cannot be deleted');
    }

    update_user_meta($user_id, 'wad_deletion_request_time', time());
    wp_logout();
    wp_send_json_success();
}

/**
 * 4. 登录拦截与账号恢复逻辑
 */
add_action('wp_login', 'wad_intercept_login_check', 10, 2);
function wad_intercept_login_check($user_login, $user) {
    $request_time = get_user_meta($user->ID, 'wad_deletion_request_time', true);
    if ($request_time) {
        set_transient('wad_pending_cancel_' . $user->ID, true, 30);
    }
}

add_action('template_redirect', 'wad_check_relogin_interception');
add_action('admin_init', 'wad_check_relogin_interception');
function wad_check_relogin_interception() {
    if (!is_user_logged_in()) return;
    $uid = get_current_user_id();
    
    if (get_transient('wad_pending_cancel_' . $uid)) {
        delete_transient('wad_pending_cancel_' . $uid);
        $cancel_url = wp_nonce_url(admin_url('admin-ajax.php?action=wad_cancel_self'), 'wad_cancel_self_nonce');
        $logout_url = wp_logout_url(home_url());
        
        echo "<script>
            if(confirm('" . esc_js(__('您的账号正在注销冷静期内，是否撤销注销申请并继续登录？\n点击“取消”将继续注销并退出登录。', 'account-deleter')) . "')){
                location.href = '" . $cancel_url . "';
            } else {
                location.href = '" . $logout_url . "';
            }
        </script>";
        exit;
    }
}

add_action('wp_ajax_wad_cancel_self', 'wad_handle_cancel_self');
function wad_handle_cancel_self() {
    check_admin_referer('wad_cancel_self_nonce');
    $uid = get_current_user_id();
    if ($uid) {
        delete_user_meta($uid, 'wad_deletion_request_time');
        wp_redirect(add_query_arg('wad_status', 'recovered', home_url()));
        exit;
    }
}

add_action('wp_footer', 'wad_recovery_notice');
function wad_recovery_notice() {
    if (isset($_GET['wad_status']) && $_GET['wad_status'] === 'recovered') {
        echo "<script>
            alert('" . esc_js(__('账号恢复成功！您的注销申请已撤销。', 'account-deleter')) . "');
            if (window.history.replaceState) {
                const url = new URL(window.location.href);
                url.searchParams.delete('wad_status');
                window.history.replaceState({}, document.title, url.pathname);
            }
        </script>";
    }
}

/**
 * 5. 管理员后台界面
 */
add_action('admin_menu', 'wad_add_admin_menu');
function wad_add_admin_menu() {
    add_users_page(
        __('账号注销管理', 'account-deleter'),
        __('账号注销', 'account-deleter'),
        'manage_options',
        'wad-settings',
        'wad_render_admin_page'
    );
}

function wad_render_admin_page() {
    if (!current_user_can('manage_options')) return;

    // 处理操作逻辑
    if (isset($_GET['action_type']) && isset($_GET['user_id'])) {
        check_admin_referer('wad_admin_action');
        
        $target_uid = intval($_GET['user_id']);
        if ($_GET['action_type'] === 'delete') {
            require_once(ABSPATH . 'wp-admin/includes/user.php');
            wp_delete_user($target_uid);
            echo "<div class='updated'><p>" . __('用户已永久删除。', 'account-deleter') . "</p></div>";
        } elseif ($_GET['action_type'] === 'cancel') {
            delete_user_meta($target_uid, 'wad_deletion_request_time');
            echo "<div class='updated'><p>" . __('注销申请已成功撤销。', 'account-deleter') . "</p></div>";
        }
    }

    // 保存设置
    if (isset($_POST['wad_save_settings'])) {
        check_admin_referer('wad_save_settings_nonce');
        update_option('wad_deletion_days', intval($_POST['wad_days']));
        update_option('wad_deletion_notice', sanitize_textarea_field($_POST['wad_notice']));
        echo "<div class='updated'><p>" . __('设置已保存。', 'account-deleter') . "</p></div>";
    }

    $days = get_option('wad_deletion_days', 7);
    $notice = get_option('wad_deletion_notice', "您的账号将在 {days} 天后被永久删除，请知悉。");
    $users = get_users(['meta_key' => 'wad_deletion_request_time']);

    ?>
    <div class="wrap">
        <h1><?php _e('账号注销管理', 'account-deleter'); ?></h1>
        <form method="post" style="background:#fff; padding:20px; border:1px solid #ccd0d4; margin:20px 0; border-radius:8px;">
            <?php wp_nonce_field('wad_save_settings_nonce'); ?>
            <h3><?php _e('注销设置', 'account-deleter'); ?></h3>
            <table class="form-table">
                <tr>
                    <th><?php _e('冷静天数', 'account-deleter'); ?></th>
                    <td><input type="number" name="wad_days" value="<?php echo esc_attr($days); ?>"> <?php _e('天', 'account-deleter'); ?></td>
                </tr>
                <tr>
                    <th><?php _e('注销须知内容', 'account-deleter'); ?></th>
                    <td>
                        <textarea name="wad_notice" rows="3" class="large-text"><?php echo esc_textarea($notice); ?></textarea>
                        <p class="description"><?php _e('支持 {days} 动态显示天数。', 'account-deleter'); ?></p>
                    </td>
                </tr>
            </table>
            <p><input type="submit" name="wad_save_settings" class="button button-primary" value="<?php _e('保存设置', 'account-deleter'); ?>"></p>
        </form>

        <h3><?php _e('当前申请注销列表', 'account-deleter'); ?></h3>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('用户名', 'account-deleter'); ?></th>
                    <th><?php _e('电子邮箱', 'account-deleter'); ?></th>
                    <th><?php _e('申请时间', 'account-deleter'); ?></th>
                    <th><?php _e('预计完成时间', 'account-deleter'); ?></th>
                    <th><?php _e('倒计时状态', 'account-deleter'); ?></th>
                    <th><?php _e('操作', 'account-deleter'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($users): foreach ($users as $u): 
                    $req_time = get_user_meta($u->ID, 'wad_deletion_request_time', true);
                    $target_time = $req_time + ($days * DAY_IN_SECONDS);
                ?>
                <tr>
                    <td><strong><?php echo esc_html($u->user_login); ?></strong></td>
                    <td><?php echo esc_html($u->user_email); ?></td>
                    <td><?php echo date_i18n(get_option('date_format') . ' H:i:s', $req_time); ?></td>
                    <td><?php echo date_i18n(get_option('date_format') . ' H:i:s', $target_time); ?></td>
                    <td><span class="wad-countdown" data-time="<?php echo esc_attr($target_time); ?>">...</span></td>
                    <td>
                        <?php 
                        $cancel_link = wp_nonce_url("?page=wad-settings&action_type=cancel&user_id=".$u->ID, 'wad_admin_action');
                        $delete_link = wp_nonce_url("?page=wad-settings&action_type=delete&user_id=".$u->ID, 'wad_admin_action');
                        ?>
                        <a href="<?php echo $cancel_link; ?>" class="button" onclick="return confirm('<?php _e('确定要撤销该用户的注销申请吗？', 'account-deleter'); ?>')"><?php _e('撤销注销', 'account-deleter'); ?></a>
                        <a href="<?php echo $delete_link; ?>" class="button button-link-delete" style="color:#d63638" onclick="return confirm('<?php _e('警告：立即注销将永久删除该用户！\n确定继续吗？', 'account-deleter'); ?>')"><?php _e('立即注销', 'account-deleter'); ?></a>
                    </td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="6"><?php _e('暂无待处理的注销申请。', 'account-deleter'); ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <script>
    function updateCountdowns() {
        const now = Math.floor(Date.now() / 1000);
        document.querySelectorAll('.wad-countdown').forEach(el => {
            const target = parseInt(el.dataset.time);
            const diff = target - now;
            if (diff <= 0) {
                el.innerHTML = '<span style="color:red;">已到期</span>';
            } else {
                const d = Math.floor(diff / 86400);
                const h = Math.floor((diff % 86400) / 3600);
                const m = Math.floor((diff % 3600) / 60);
                const s = diff % 60;
                el.innerText = d + '天 ' + h + ':' + m + ':' + s;
            }
        });
    }
    setInterval(updateCountdowns, 1000);
    updateCountdowns();
    </script>
    <?php
}

/**
 * 6. 定时自动删除到期用户
 */
add_action('wp_account_deletion_cron', 'wad_cron_deletion_process');
function wad_cron_deletion_process() {
    $days = get_option('wad_deletion_days', 7);
    $users = get_users(['meta_key' => 'wad_deletion_request_time']);
    if (!$users) return;

    require_once(ABSPATH . 'wp-admin/includes/user.php');
    foreach ($users as $u) {
        $req_time = get_user_meta($u->ID, 'wad_deletion_request_time', true);
        if (time() >= ($req_time + ($days * DAY_IN_SECONDS))) {
            wp_delete_user($u->ID);
        }
    }
}