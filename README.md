# Bark Notify

Contributors:      ROYIANS

Tags:              notification, bark, push, comments, mobile

Requires at least: 5.8

Tested up to:      6.7

Stable tag:        1.0.0

Requires PHP:      7.4

License:           GPLv2 or later

通过 Bark 向 iPhone 发送 WordPress 实时推送通知。

## Description

Bark Notify 是一款轻量级 WordPress 插件，将站点事件通过 Bark API 实时推送到你的 iPhone。

**支持的通知事件：**

* 💬 新评论（已审核）
* ⏳ 评论过审（待审核 → 已批准）
* 📝 新文章发布
* 👤 新用户注册
* 🔑 用户登录成功
* ⚠️ 登录失败（含 IP，便于安全监控）
* 🔄 插件/主题更新完成

**特性：**

* 支持官方及自建 bark-server
* 可配置通知级别（Active / Time Sensitive / Passive / Critical）
* 支持自定义铃声、图标、消息分组
* 一键发送测试推送
* 简洁美观的配置页面

## Installation

1. 将 `bark-notify` 文件夹上传到 `/wp-content/plugins/` 目录
2. 在 WordPress 后台"插件"页面启用 **Bark Notify**
3. 前往 **设置 → Bark 通知** 填入你的 Device Key
4. 勾选需要的通知事件，保存
5. 点击"发送测试推送"确认配置正确

## Changelog

= 1.0.0 =

* 初始版本发布
