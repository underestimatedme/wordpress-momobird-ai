<?php
/**
 * Plugin Name:       MomoBird AI
 * Plugin URI:        https://momobird.atlaspaces.com/docs/wordpress
 * Description:       把公开文章同步到 MomoBird 知识库，在博客前台提供问答助手和文章播客。
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            MomoBird
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       momobird-ai
 */

defined('ABSPATH') || exit;

define('MOMOBIRD_AI_VERSION', '0.1.0');
define('MOMOBIRD_AI_FILE', __FILE__);

require_once __DIR__ . '/includes/bootstrap.php';

MomoBirdWP_Plugin::register(__FILE__);
