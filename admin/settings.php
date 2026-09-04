<?php
/**
 * Settings admin page mount.
 *
 * The settings page reuses every helper declared in admin/wizard.php
 * (menu registration is shared via add_submenu_page, the React bundle
 * is the same, and EnvDetector emits the same boot payload). This file
 * exists so the include graph in smaily-connect.php has a 1:1 mapping
 * between mount points and entry files — a convention the Code agent
 * relies on when looking up "what does the settings page do".
 *
 * Including admin/wizard.php here is intentional. WordPress' require
 * helpers dedupe by realpath so the second include short-circuits
 * regardless of load order.
 *
 * @package Smaily\Connect
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

require_once SMAILY_CONNECT_PLUGIN_PATH . 'admin/wizard.php';
