<?php
/**
 * Plugin Name: Bark Notify
 * Plugin URI:  https://github.com/ROYIANS/wordpress-bark-notification-plugin
 * Description: 通过 Bark 向 iPhone 发送 WordPress 通知，支持新评论、新用户注册、新文章发布等事件。
 * Version:     1.0.0
 * Author:      ROYIANS
 * License:     GPL-2.0+
 * Text Domain: bark-notify
 */

defined( 'ABSPATH' ) || exit;

define( 'BARK_NOTIFY_VERSION', '1.0.0' );
define( 'BARK_NOTIFY_FILE', __FILE__ );
define( 'BARK_NOTIFY_DIR', plugin_dir_path( __FILE__ ) );
define( 'BARK_NOTIFY_URL', plugin_dir_url( __FILE__ ) );

// ─── Core API ────────────────────────────────────────────────────────────────

/**
 * 发送 Bark 推送
 *
 * @param string $title
 * @param string $body
 * @param array  $extra  额外参数（icon / group / url / level / sound 等）
 * @return bool|WP_Error
 */
function bark_notify_send( string $title, string $body, array $extra = [] ) {
    $options = get_option( 'bark_notify_options', [] );
    $key     = trim( $options['device_key'] ?? '' );
    $server  = rtrim( trim( $options['server_url'] ?? 'https://api.day.app' ), '/' );

    if ( empty( $key ) ) {
        return new WP_Error( 'bark_no_key', __( 'Bark device key 未设置。', 'bark-notify' ) );
    }

    $payload = array_merge( [
        'title'      => $title,
        'body'       => $body,
        'device_key' => $key,
        'icon'       => $options['icon_url'] ?? '',
        'group'      => $options['group']    ?? 'WordPress',
        'level'      => $options['level']    ?? 'active',
        'sound'      => $options['sound']    ?? '',
        'isArchive'  => '1',
    ], $extra );

    // 清空空字符串
    $payload = array_filter( $payload, fn( $v ) => $v !== '' );

    $response = wp_remote_post(
        $server . '/push',
        [
            'timeout'     => 10,
            'headers'     => [ 'Content-Type' => 'application/json; charset=utf-8' ],
            'body'        => wp_json_encode( $payload ),
            'data_format' => 'body',
        ]
    );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code( $response );
    if ( $code !== 200 ) {
        return new WP_Error( 'bark_api_error', sprintf( __( 'Bark API 返回错误状态码：%d', 'bark-notify' ), $code ) );
    }

    return true;
}

// ─── Event Hooks ─────────────────────────────────────────────────────────────

class Bark_Notify_Events {

    public function __construct() {
        $options = get_option( 'bark_notify_options', [] );

        if ( ! empty( $options['event_comment'] ) ) {
            add_action( 'wp_insert_comment',        [ $this, 'on_new_comment' ], 10, 2 );
        }
        if ( ! empty( $options['event_comment_pending'] ) ) {
            add_action( 'comment_unapproved_to_approved', [ $this, 'on_comment_approved' ] );
        }
        if ( ! empty( $options['event_post'] ) ) {
            add_action( 'transition_post_status',   [ $this, 'on_post_published' ], 10, 3 );
        }
        if ( ! empty( $options['event_user'] ) ) {
            add_action( 'user_register',            [ $this, 'on_user_register' ] );
        }
        if ( ! empty( $options['event_login_fail'] ) ) {
            add_action( 'wp_login_failed',          [ $this, 'on_login_failed' ] );
        }
        if ( ! empty( $options['event_update'] ) ) {
            add_action( 'upgrader_process_complete', [ $this, 'on_update_complete' ], 10, 2 );
        }
        if ( ! empty( $options['event_login'] ) ) {
            add_action( 'wp_login',                 [ $this, 'on_user_login' ], 10, 2 );
        }
    }

    // 新评论
    public function on_new_comment( $comment_id, $comment ) {
        if ( (int) $comment->comment_approved === 1 ) {
            $post  = get_post( $comment->comment_post_ID );
            $title = '💬 新评论';
            $body  = sprintf(
                "文章：%s\n作者：%s\n内容：%s",
                $post ? $post->post_title : '(未知)',
                $comment->comment_author,
                mb_substr( strip_tags( $comment->comment_content ), 0, 100 )
            );
            bark_notify_send( $title, $body, [
                'url' => get_comment_link( $comment_id ),
            ] );
        }
    }

    // 评论过审
    public function on_comment_approved( $comment ) {
        $post  = get_post( $comment->comment_post_ID );
        $title = '✅ 评论已过审';
        $body  = sprintf(
            "文章：%s\n作者：%s\n内容：%s",
            $post ? $post->post_title : '(未知)',
            $comment->comment_author,
            mb_substr( strip_tags( $comment->comment_content ), 0, 100 )
        );
        bark_notify_send( $title, $body, [
            'url' => get_comment_link( $comment->comment_ID ),
        ] );
    }

    // 文章发布
    public function on_post_published( $new_status, $old_status, $post ) {
        if ( $new_status === 'publish' && $old_status !== 'publish'
             && $post->post_type === 'post'
             && ! ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
        ) {
            bark_notify_send(
                '📝 新文章已发布',
                sprintf( "标题：%s\n作者：%s", $post->post_title, get_the_author_meta( 'display_name', $post->post_author ) ),
                [ 'url' => get_permalink( $post->ID ) ]
            );
        }
    }

    // 新用户注册
    public function on_user_register( $user_id ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) return;
        bark_notify_send(
            '👤 新用户注册',
            sprintf( "用户名：%s\n邮箱：%s", $user->user_login, $user->user_email ),
            [ 'level' => 'timeSensitive' ]
        );
    }

    // 登录失败
    public function on_login_failed( $username ) {
        bark_notify_send(
            '⚠️ 登录失败',
            sprintf( "用户名：%s\nIP：%s\n时间：%s", $username, $_SERVER['REMOTE_ADDR'] ?? '-', current_time( 'Y-m-d H:i:s' ) ),
            [ 'level' => 'timeSensitive' ]
        );
    }

    // 用户登录成功
    public function on_user_login( $user_login, $user ) {
        bark_notify_send(
            '🔑 用户登录',
            sprintf( "用户名：%s\nIP：%s\n时间：%s", $user_login, $_SERVER['REMOTE_ADDR'] ?? '-', current_time( 'Y-m-d H:i:s' ) )
        );
    }

    // 插件/主题更新完成
    public function on_update_complete( $upgrader, $hook_extra ) {
        $type  = $hook_extra['type']   ?? 'unknown';
        $action = $hook_extra['action'] ?? 'unknown';
        if ( $action !== 'update' ) return;
        bark_notify_send(
            '🔄 WordPress 更新完成',
            sprintf( "类型：%s\n时间：%s", $type, current_time( 'Y-m-d H:i:s' ) )
        );
    }
}

// ─── Settings Page ────────────────────────────────────────────────────────────

class Bark_Notify_Settings {

    const OPTION_KEY  = 'bark_notify_options';
    const MENU_SLUG   = 'bark-notify';

    public function __construct() {
        add_action( 'admin_menu',    [ $this, 'add_menu' ] );
        add_action( 'admin_init',    [ $this, 'register_settings' ] );
        add_action( 'admin_notices', [ $this, 'maybe_show_notice' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_styles' ] );
    }

    public function add_menu() {
        add_options_page(
            'Bark 推送通知',
            'Bark 通知',
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render_page' ]
        );
    }

    public function enqueue_styles( $hook ) {
        if ( $hook !== 'settings_page_' . self::MENU_SLUG ) return;
        wp_add_inline_style( 'wp-admin', $this->inline_css() );
    }

    public function register_settings() {
        register_setting( self::OPTION_KEY, self::OPTION_KEY, [ $this, 'sanitize' ] );
    }

    public function sanitize( $input ) {
        $clean = [];
        $text_fields = [ 'device_key', 'server_url', 'icon_url', 'group', 'sound' ];
        foreach ( $text_fields as $f ) {
            $clean[ $f ] = sanitize_text_field( $input[ $f ] ?? '' );
        }
        $clean['level'] = in_array( $input['level'] ?? '', [ 'active', 'timeSensitive', 'passive', 'critical' ], true )
            ? $input['level'] : 'active';

        $checkboxes = [ 'event_comment', 'event_comment_pending', 'event_post', 'event_user', 'event_login', 'event_login_fail', 'event_update' ];
        foreach ( $checkboxes as $cb ) {
            $clean[ $cb ] = ! empty( $input[ $cb ] ) ? 1 : 0;
        }
        return $clean;
    }

    // 发送测试推送
    public function maybe_show_notice() {
        if ( isset( $_GET['bark_test'] ) && check_admin_referer( 'bark_test_send' ) ) {
            $result = bark_notify_send( '🎉 测试推送', 'Bark Notify 插件配置成功！', [ 'level' => 'active' ] );
            if ( is_wp_error( $result ) ) {
                echo '<div class="notice notice-error"><p>发送失败：' . esc_html( $result->get_error_message() ) . '</p></div>';
            } else {
                echo '<div class="notice notice-success"><p>✅ 测试推送已发送，请查看手机！</p></div>';
            }
        }
    }

    public function render_page() {
        $options = get_option( self::OPTION_KEY, [] );
        $test_url = wp_nonce_url( admin_url( 'options-general.php?page=' . self::MENU_SLUG . '&bark_test=1' ), 'bark_test_send' );
        ?>
        <div class="wrap bark-wrap">
            <div class="bark-header">
                <span class="bark-logo">🔔</span>
                <h1>Bark 推送通知</h1>
                <p class="bark-tagline">将 WordPress 事件实时推送到你的 iPhone</p>
            </div>

            <div class="bark-grid">

                <!-- 左列：设置表单 -->
                <div class="bark-main">
                    <form method="post" action="options.php">
                        <?php settings_fields( self::OPTION_KEY ); ?>

                        <!-- 基本配置 -->
                        <div class="bark-card">
                            <h2 class="bark-card-title">⚙️ 基本配置</h2>

                            <div class="bark-field">
                                <label for="bark_device_key">Device Key <span class="required">*</span></label>
                                <input type="text" id="bark_device_key"
                                       name="bark_notify_options[device_key]"
                                       value="<?php echo esc_attr( $options['device_key'] ?? '' ); ?>"
                                       placeholder="从 Bark APP 获取你的 Key"
                                       class="bark-input" />
                                <p class="desc">打开 Bark APP，首页显示的 Key 或测试 URL 中的路径部分。</p>
                            </div>

                            <div class="bark-field">
                                <label for="bark_server_url">推送服务器</label>
                                <input type="url" id="bark_server_url"
                                       name="bark_notify_options[server_url]"
                                       value="<?php echo esc_attr( $options['server_url'] ?? 'https://api.day.app' ); ?>"
                                       placeholder="https://api.day.app"
                                       class="bark-input" />
                                <p class="desc">默认使用官方服务器。如果自建了 bark-server，可替换为自己的地址。</p>
                            </div>

                            <div class="bark-field">
                                <label for="bark_group">消息分组</label>
                                <input type="text" id="bark_group"
                                       name="bark_notify_options[group]"
                                       value="<?php echo esc_attr( $options['group'] ?? 'WordPress' ); ?>"
                                       placeholder="WordPress"
                                       class="bark-input bark-input-sm" />
                            </div>
                        </div>

                        <!-- 通知样式 -->
                        <div class="bark-card">
                            <h2 class="bark-card-title">🎨 通知样式</h2>

                            <div class="bark-field">
                                <label for="bark_level">中断级别</label>
                                <select id="bark_level" name="bark_notify_options[level]" class="bark-select">
                                    <?php
                                    $levels = [
                                        'active'        => 'Active（默认，立即亮屏）',
                                        'timeSensitive' => 'Time Sensitive（专注模式下也显示）',
                                        'passive'       => 'Passive（安静添加到通知列表）',
                                        'critical'      => 'Critical（静音模式下响铃）',
                                    ];
                                    foreach ( $levels as $val => $label ) {
                                        printf(
                                            '<option value="%s" %s>%s</option>',
                                            esc_attr( $val ),
                                            selected( $options['level'] ?? 'active', $val, false ),
                                            esc_html( $label )
                                        );
                                    }
                                    ?>
                                </select>
                            </div>

                            <div class="bark-field">
                                <label for="bark_sound">铃声</label>
                                <input type="text" id="bark_sound"
                                       name="bark_notify_options[sound]"
                                       value="<?php echo esc_attr( $options['sound'] ?? '' ); ?>"
                                       placeholder="例如：minuet（留空使用默认铃声）"
                                       class="bark-input bark-input-sm" />
                                <p class="desc">在 Bark APP 内可预览所有可用铃声名称。</p>
                            </div>

                            <div class="bark-field">
                                <label for="bark_icon_url">自定义图标 URL</label>
                                <input type="url" id="bark_icon_url"
                                       name="bark_notify_options[icon_url]"
                                       value="<?php echo esc_attr( $options['icon_url'] ?? '' ); ?>"
                                       placeholder="https://example.com/icon.png（留空使用 Bark 默认图标）"
                                       class="bark-input" />
                            </div>
                        </div>

                        <!-- 事件开关 -->
                        <div class="bark-card">
                            <h2 class="bark-card-title">📡 通知事件</h2>
                            <p class="desc" style="margin-bottom:16px;">选择哪些事件需要触发推送通知。</p>

                            <?php
                            $events = [
                                'event_comment'         => [ '💬', '新评论（已审核）',     '有新的已审核评论时推送' ],
                                'event_comment_pending' => [ '⏳', '评论过审',              '评论从待审核变为已批准时推送' ],
                                'event_post'            => [ '📝', '新文章发布',            '文章状态变为"已发布"时推送' ],
                                'event_user'            => [ '👤', '新用户注册',            '有新用户完成注册时推送' ],
                                'event_login'           => [ '🔑', '用户登录成功',          '任何用户登录成功时推送' ],
                                'event_login_fail'      => [ '⚠️', '登录失败',              '登录失败时推送（含 IP 地址，便于安全监控）' ],
                                'event_update'          => [ '🔄', '插件/主题更新完成',     'WordPress 自动更新任务完成后推送' ],
                            ];
                            foreach ( $events as $key => [ $icon, $label, $desc ] ) :
                                $checked = ! empty( $options[ $key ] );
                            ?>
                            <label class="bark-toggle-row <?php echo $checked ? 'is-on' : ''; ?>">
                                <span class="bark-toggle-info">
                                    <span class="bark-toggle-icon"><?php echo $icon; ?></span>
                                    <span>
                                        <strong><?php echo esc_html( $label ); ?></strong>
                                        <em><?php echo esc_html( $desc ); ?></em>
                                    </span>
                                </span>
                                <span class="bark-toggle-switch">
                                    <input type="checkbox"
                                           name="bark_notify_options[<?php echo esc_attr( $key ); ?>]"
                                           value="1" <?php checked( $checked ); ?>
                                           onchange="this.closest('.bark-toggle-row').classList.toggle('is-on', this.checked)" />
                                    <span class="bark-slider"></span>
                                </span>
                            </label>
                            <?php endforeach; ?>
                        </div>

                        <?php submit_button( '💾 保存设置', 'primary bark-save-btn', 'submit', false ); ?>
                    </form>
                </div>

                <!-- 右列：状态 & 帮助 -->
                <div class="bark-sidebar">

                    <div class="bark-card bark-status-card">
                        <h2 class="bark-card-title">📊 状态</h2>
                        <?php
                        $key_set = ! empty( $options['device_key'] );
                        ?>
                        <div class="bark-status-row">
                            <span>Device Key</span>
                            <span class="bark-badge <?php echo $key_set ? 'ok' : 'warn'; ?>">
                                <?php echo $key_set ? '✅ 已配置' : '❌ 未设置'; ?>
                            </span>
                        </div>
                        <div class="bark-status-row">
                            <span>推送服务器</span>
                            <span class="bark-badge ok">
                                <?php echo esc_html( $options['server_url'] ?? 'https://api.day.app' ); ?>
                            </span>
                        </div>
                        <div class="bark-status-row">
                            <span>插件版本</span>
                            <span class="bark-badge ok"><?php echo BARK_NOTIFY_VERSION; ?></span>
                        </div>

                        <?php if ( $key_set ) : ?>
                        <a href="<?php echo esc_url( $test_url ); ?>" class="bark-test-btn">
                            📱 发送测试推送
                        </a>
                        <?php else : ?>
                        <p class="desc" style="text-align:center;margin-top:12px;">请先配置 Device Key 后测试</p>
                        <?php endif; ?>
                    </div>

                    <div class="bark-card">
                        <h2 class="bark-card-title">📖 使用说明</h2>
                        <ol class="bark-help-list">
                            <li>在 iPhone 上安装 <strong>Bark APP</strong>（App Store 搜索 Bark）</li>
                            <li>打开 APP，复制首页的 <strong>Device Key</strong></li>
                            <li>粘贴到左侧"Device Key"输入框</li>
                            <li>勾选你需要的通知事件</li>
                            <li>保存设置后点击"发送测试推送"验证</li>
                        </ol>
                    </div>

                    <div class="bark-card">
                        <h2 class="bark-card-title">🔗 相关链接</h2>
                        <ul class="bark-link-list">
                            <li><a href="https://apps.apple.com/app/bark-customed-notifications/id1403753865" target="_blank">📲 Bark on App Store</a></li>
                            <li><a href="https://bark.day.app" target="_blank">📚 Bark 官方文档</a></li>
                            <li><a href="https://github.com/Finb/bark-server" target="_blank">🖥️ 自建 bark-server</a></li>
                        </ul>
                    </div>

                </div><!-- .bark-sidebar -->
            </div><!-- .bark-grid -->
        </div><!-- .bark-wrap -->
        <?php
    }

    private function inline_css(): string {
        return '
        /* ── Bark Notify Admin Styles ── */
        .bark-wrap { max-width: 1100px; }
        .bark-header { display:flex; align-items:center; gap:12px; padding:24px 0 16px; border-bottom:1px solid #e0e0e0; margin-bottom:28px; }
        .bark-logo { font-size:36px; line-height:1; }
        .bark-header h1 { margin:0; font-size:24px; }
        .bark-tagline { margin:2px 0 0; color:#666; font-size:13px; }

        .bark-grid { display:grid; grid-template-columns:1fr 300px; gap:24px; align-items:start; }
        @media (max-width:900px) { .bark-grid { grid-template-columns:1fr; } }

        .bark-card { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:22px 24px; margin-bottom:20px; box-shadow:0 1px 3px rgba(0,0,0,.05); }
        .bark-card-title { font-size:15px; font-weight:600; margin:0 0 18px; padding-bottom:10px; border-bottom:1px solid #f0f0f0; }

        .bark-field { margin-bottom:18px; }
        .bark-field label { display:block; font-weight:500; margin-bottom:6px; font-size:13px; }
        .bark-field label .required { color:#e53e3e; }
        .bark-input { width:100%; max-width:480px; padding:8px 12px; border:1px solid #d1d5db; border-radius:6px; font-size:13px; transition:border-color .2s; }
        .bark-input:focus { border-color:#2563eb; outline:none; box-shadow:0 0 0 3px rgba(37,99,235,.15); }
        .bark-input-sm { max-width:260px; }
        .bark-select { padding:8px 12px; border:1px solid #d1d5db; border-radius:6px; font-size:13px; min-width:280px; }
        .bark-field .desc { margin:5px 0 0; color:#6b7280; font-size:12px; }

        /* Toggle rows */
        .bark-toggle-row { display:flex; align-items:center; justify-content:space-between; padding:12px 14px; margin-bottom:8px; border:1px solid #e5e7eb; border-radius:8px; cursor:pointer; transition:background .15s, border-color .15s; }
        .bark-toggle-row:hover { background:#f9fafb; }
        .bark-toggle-row.is-on { background:#eff6ff; border-color:#bfdbfe; }
        .bark-toggle-info { display:flex; align-items:center; gap:10px; }
        .bark-toggle-icon { font-size:18px; width:24px; text-align:center; }
        .bark-toggle-info strong { display:block; font-size:13px; }
        .bark-toggle-info em { display:block; font-size:11px; color:#6b7280; font-style:normal; }

        /* Switch */
        .bark-toggle-switch { position:relative; display:inline-block; width:42px; height:22px; flex-shrink:0; }
        .bark-toggle-switch input { opacity:0; width:0; height:0; }
        .bark-slider { position:absolute; inset:0; background:#d1d5db; border-radius:22px; transition:.25s; }
        .bark-slider:before { content:""; position:absolute; left:3px; bottom:3px; width:16px; height:16px; background:#fff; border-radius:50%; transition:.25s; }
        .bark-toggle-switch input:checked + .bark-slider { background:#2563eb; }
        .bark-toggle-switch input:checked + .bark-slider:before { transform:translateX(20px); }

        .bark-save-btn.button-primary { height:40px; padding:0 28px; font-size:14px; border-radius:6px; margin-top:4px; }

        /* Sidebar */
        .bark-status-card {}
        .bark-status-row { display:flex; justify-content:space-between; align-items:center; padding:8px 0; border-bottom:1px solid #f3f4f6; font-size:13px; }
        .bark-status-row:last-of-type { border:none; }
        .bark-badge { font-size:11px; padding:3px 8px; border-radius:20px; background:#f3f4f6; }
        .bark-badge.ok { background:#d1fae5; color:#065f46; }
        .bark-badge.warn { background:#fee2e2; color:#991b1b; }
        .bark-test-btn { display:block; text-align:center; margin-top:16px; padding:10px; background:#2563eb; color:#fff!important; border-radius:7px; text-decoration:none; font-size:13px; font-weight:500; transition:background .2s; }
        .bark-test-btn:hover { background:#1d4ed8; }

        .bark-help-list { margin:0; padding-left:20px; }
        .bark-help-list li { font-size:13px; margin-bottom:8px; color:#374151; line-height:1.5; }
        .bark-link-list { margin:0; padding:0; list-style:none; }
        .bark-link-list li { margin-bottom:8px; }
        .bark-link-list a { font-size:13px; color:#2563eb; text-decoration:none; }
        .bark-link-list a:hover { text-decoration:underline; }
        ';
    }
}

// ─── Init ─────────────────────────────────────────────────────────────────────

function bark_notify_init() {
    new Bark_Notify_Settings();
    if ( get_option( 'bark_notify_options' ) ) {
        new Bark_Notify_Events();
    }
}
add_action( 'plugins_loaded', 'bark_notify_init' );

// 激活时设置默认选项
register_activation_hook( BARK_NOTIFY_FILE, function () {
    if ( ! get_option( 'bark_notify_options' ) ) {
        update_option( 'bark_notify_options', [
            'server_url'            => 'https://api.day.app',
            'group'                 => 'WordPress',
            'level'                 => 'active',
            'event_comment'         => 1,
            'event_comment_pending' => 1,
            'event_post'            => 1,
            'event_user'            => 0,
            'event_login'           => 0,
            'event_login_fail'      => 1,
            'event_update'          => 1,
        ] );
    }
} );
