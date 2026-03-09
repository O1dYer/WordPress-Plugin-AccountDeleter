<?php
/*
Plugin Name: 简易账号注销插件
Description: 在任何页面（文章页以外）插入代码[delete_account]即可使用。支持设置注销冷静期、后台管理。
Version: 26.3.9
Author: 欧叶
Author URI: https://github.com/O1dYer
*/

if (!defined('ABSPATH')) exit;

// --- 1. 插件安装与定时任务 ---
register_activation_hook(__FILE__, function() {
    if (!wp_next_scheduled('wp_account_deletion_cron')) {
        wp_schedule_event(time(), 'hourly', 'wp_account_deletion_cron');
    }
});

register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('wp_account_deletion_cron');
});

// --- 2. 前端短代码功能 ---
add_shortcode('delete_account', function() {
    if (!is_page()) {
        return '[delete_account]';
    }

    // 统一的警告提示框样式
    $error_box_style = 'style="color:#d63638; font-weight:bold; padding:15px; background:#fff5f5; border-left:4px solid #d63638; border-radius:4px; margin-bottom:20px; font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;"';

    // 检查登录状态
    if (!is_user_logged_in()) {
        return '<p ' . $error_box_style . '>请先登录再执行注销账号操作。</p>';
    }
    
    $user = wp_get_current_user();
    // 检查是否为管理员
    if (in_array('administrator', $user->roles)) {
        return '<p ' . $error_box_style . '>为了保障安全，管理员账号不能从前端注销。</p>';
    }

    $days = get_option('wad_deletion_days', 7);
    $notice = get_option('wad_deletion_notice', '若申请注销，您的账号将在 {days} 天后被永久删除，请知悉。');
    $notice = str_replace('{days}', $days, $notice);

    ob_start();
    ?>
    <style>
        .wad-modern-card {
            background: #ffffff;
            border: 1px solid #eaeaea;
            border-radius: 12px;
            padding: 24px;
            max-width: 500px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        .wad-modern-card p {
            color: #4a5568;
            font-size: 15px;
            line-height: 1.6;
            margin-bottom: 20px;
        }
        .wad-modern-btn {
            background-color: #ffffff;
            color: #e53e3e;
            border: 1.5px solid #e53e3e;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-block;
        }
        .wad-modern-btn:hover {
            background-color: #fff5f5;
            box-shadow: 0 2px 4px rgba(229, 62, 62, 0.1);
            transform: translateY(-1px);
        }
    </style>

    <div class="wad-modern-card">
        <p><?php echo nl2br(esc_html($notice)); ?></p>
        <button id="wad-delete-btn" class="wad-modern-btn">申请注销账号</button>
    </div>

    <script>
    (function() {
        const btn = document.getElementById('wad-delete-btn');
        if(!btn) return;
        btn.addEventListener('click', function() {
            const days = <?php echo intval($days); ?>;
            if (confirm('确定要申请注销吗？\n您的账号将在 ' + days + ' 天后被永久删除。在此期间重新登录可撤销申请。\n点击确定后将自动退出登录。')) {
                fetch('<?php echo admin_url('admin-ajax.php'); ?>?action=wad_request_deletion')
                .then(response => {
                    alert('申请成功，您的账号已退出登录并进入冷静期。');
                    window.location.href = '<?php echo home_url(); ?>';
                })
                .catch(err => {
                    alert('请求处理失败，请稍后重试。');
                });
            }
        });
    })();
    </script>
    <?php
    return ob_get_clean();
});

// --- 3. 处理注销申请的 AJAX 逻辑 ---
add_action('wp_ajax_wad_request_deletion', function() {
    if (!is_user_logged_in()) {
        wp_send_json_error('Unauthorized');
    }

    $user_id = get_current_user_id();
    $user = get_userdata($user_id);

    if (in_array('administrator', $user->roles)) {
        wp_send_json_error('Admin cannot be deleted');
    }

    update_user_meta($user_id, 'wad_deletion_request_time', time());
    wp_logout();
    wp_send_json_success('Request logged and logged out');
});

// --- 4. 登录拦截与账号恢复逻辑 ---
add_action('wp_login', function($user_login, $user) {
    $request_time = get_user_meta($user->ID, 'wad_deletion_request_time', true);
    if ($request_time) {
        set_transient('wad_pending_cancel_' . $user->ID, true, 30);
    }
}, 10, 2);

add_action('template_redirect', 'wad_check_relogin_interception');
add_action('admin_init', 'wad_check_relogin_interception');

function wad_check_relogin_interception() {
    if (!is_user_logged_in()) return;
    $uid = get_current_user_id();
    if (get_transient('wad_pending_cancel_' . $uid)) {
        delete_transient('wad_pending_cancel_' . $uid);
        echo "<script>
            if(confirm('您的账号正在注销冷静期内，是否撤销注销申请并继续登录？\\n点击“取消”将继续注销并退出登录。')){
                location.href = '" . admin_url('admin-ajax.php?action=wad_cancel_self') . "';
            } else {
                location.href = '" . wp_logout_url(home_url()) . "';
            }
        </script>";
        exit;
    }
}

add_action('wp_ajax_wad_cancel_self', function() {
    $uid = get_current_user_id();
    if ($uid) {
        delete_user_meta($uid, 'wad_deletion_request_time');
        wp_redirect(add_query_arg('wad_status', 'recovered', home_url()));
        exit;
    }
});

add_action('wp_footer', function() {
    if (isset($_GET['wad_status']) && $_GET['wad_status'] === 'recovered') {
        echo "<script>
            alert('账号恢复成功！您的注销申请已撤销。');
            if (window.history.replaceState) {
                const url = new URL(window.location.href);
                url.searchParams.delete('wad_status');
                window.history.replaceState({}, document.title, url.pathname);
            }
        </script>";
    }
});

// --- 5. 管理员后台界面 ---
add_action('admin_menu', function() {
    add_users_page('账号注销管理', '账号注销', 'manage_options', 'wad-settings', 'wad_render_admin_page');
});

function wad_render_admin_page() {
    echo '
    <style>
        .wad-admin-btn { display: inline-block !important; min-width: 90px !important; height: 30px !important; line-height: 28px !important; text-align: center !important; padding: 0 10px !important; vertical-align: middle !important; box-sizing: border-box !important; margin-right: 5px !important; text-decoration: none !important; border-radius: 4px !important; }
        .wad-btn-danger { border: 1px solid #d63638 !important; color: #d63638 !important; background: #fff !important; }
        .wad-btn-danger:hover { background: #fbe9e9 !important; }
    </style>';

    if (isset($_GET['action_type']) && isset($_GET['user_id'])) {
        $target_uid = intval($_GET['user_id']);
        if ($_GET['action_type'] == 'delete') {
            require_once(ABSPATH . 'wp-admin/includes/user.php');
            wp_delete_user($target_uid);
            echo "<script>alert('用户已永久删除。'); window.location.href='?page=wad-settings';</script>";
        } elseif ($_GET['action_type'] == 'cancel') {
            delete_user_meta($target_uid, 'wad_deletion_request_time');
            echo "<script>alert('注销申请已成功撤销。'); window.location.href='?page=wad-settings';</script>";
        }
    }

    if (isset($_POST['wad_save_settings'])) {
        update_option('wad_deletion_days', intval($_POST['wad_days']));
        update_option('wad_deletion_notice', sanitize_textarea_field($_POST['wad_notice']));
    }

    $days = get_option('wad_deletion_days', 7);
    $notice = get_option('wad_deletion_notice', "您的账号将在 {days} 天后被永久删除，请知悉。");
    $users = get_users(['meta_key' => 'wad_deletion_request_time']);

    ?>
    <div class="wrap">
        <h1>账号注销管理</h1>
        <hr>
        <form method="post" style="background:#fff; padding:20px; border:1px solid #ccd0d4; margin-bottom:20px; border-radius:8px;">
            <h3>注销设置</h3>
            <table class="form-table">
                <tr>
                    <th>冷静天数</th>
                    <td><input type="number" name="wad_days" value="<?php echo $days; ?>"> 天</td>
                </tr>
                <tr>
                    <th>注销须知内容</th>
                    <td>
                        <textarea name="wad_notice" rows="3" class="large-text"><?php echo esc_textarea($notice); ?></textarea>
                        <p class="description">支持 <code>{days}</code> 动态显示天数。</p>
                    </td>
                </tr>
            </table>
            <p><input type="submit" name="wad_save_settings" class="button button-primary" value="保存设置"></p>
        </form>

        <h3>当前申请注销列表</h3>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>用户名</th>
                    <th>电子邮箱</th>
                    <th>申请时间</th>
                    <th>预计完成时间</th>
                    <th>倒计时状态</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($users): foreach ($users as $u): 
                    $req_time = get_user_meta($u->ID, 'wad_deletion_request_time', true);
                    $target_time = $req_time + ($days * DAY_IN_SECONDS);
                ?>
                <tr>
                    <td><strong><?php echo $u->user_login; ?></strong></td>
                    <td><?php echo $u->user_email; ?></td>
                    <td><?php echo date('Y-m-d H:i:s', $req_time); ?></td>
                    <td><?php echo date('Y-m-d H:i:s', $target_time); ?></td>
                    <td><span class="wad-countdown" data-time="<?php echo $target_time; ?>">...</span></td>
                    <td style="white-space: nowrap;">
                        <a href="?page=wad-settings&action_type=cancel&user_id=<?php echo $u->ID; ?>" 
                           class="button wad-admin-btn" 
                           onclick="return confirm('确定要撤销该用户的注销申请吗？')">撤销注销</a>
                        <a href="?page=wad-settings&action_type=delete&user_id=<?php echo $u->ID; ?>" 
                           class="button wad-admin-btn wad-btn-danger" 
                           onclick="return confirm('警告：立即注销将永久删除该用户！\n确定继续吗？')">立即注销</a>
                    </td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="6">暂无待处理的注销申请。</td></tr>
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

// --- 6. 定时自动删除到期用户 ---
add_action('wp_account_deletion_cron', function() {
    $days = get_option('wad_deletion_days', 7);
    $users = get_users(['meta_key' => 'wad_deletion_request_time']);
    require_once(ABSPATH . 'wp-admin/includes/user.php');

    foreach ($users as $u) {
        $req_time = get_user_meta($u->ID, 'wad_deletion_request_time', true);
        if (time() >= ($req_time + ($days * DAY_IN_SECONDS))) {
            wp_delete_user($u->ID);
        }
    }
});