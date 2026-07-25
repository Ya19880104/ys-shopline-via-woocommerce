<?php
/**
 * Shared 電商工具箱 menu normalization.
 *
 * @package YangSheep\PluginHubClient\Admin
 */

namespace YangSheep\PluginHubClient\Admin;

use YangSheep\PluginHubClient\YSPluginHubClient;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Keeps the shared parent and utility links deterministic across vendored
 * client versions and WordPress plugin load order.
 */
final class YSToolboxMenuNormalizer {

    private const PARENT_SLUG   = 'ys-toolbox';
    private const SYSTEM_SLUG   = 'ys-hub-logs';
    private const CONTACT_URL   = 'https://yangsheep.com.tw/contact-us/';
    private const MENU_POSITION = 56;

    /**
     * @var bool
     */
    private static $registered = false;

    /**
     * Register the late menu pass once per request.
     */
    public static function register(): void {
        if ( self::$registered ) {
            return;
        }

        self::$registered = true;
        add_action( 'admin_menu', array( self::class, 'normalize' ), PHP_INT_MAX );
    }

    /**
     * Normalize the shared parent and keep utility links at the end.
     */
    public static function normalize(): void {
        self::normalize_parent();
        self::normalize_submenu();
    }

    /**
     * Correct label, icon, and numeric position even when a legacy client won.
     */
    private static function normalize_parent(): void {
        global $menu;

        if ( ! is_array( $menu ) ) {
            return;
        }

        $source_key = null;
        $entry      = null;

        foreach ( $menu as $menu_key => $item ) {
            if ( isset( $item[2] ) && self::PARENT_SLUG === $item[2] ) {
                $source_key = $menu_key;
                $entry      = $item;
                break;
            }
        }

        if ( null === $source_key || ! is_array( $entry ) ) {
            return;
        }

        $entry[0] = esc_html__( '電商工具箱', 'ys-plugin-hub-client' );
        $entry[3] = esc_html__( '電商工具箱', 'ys-plugin-hub-client' );
        $entry[6] = 'dashicons-store';

        unset( $menu[ $source_key ] );

        $target = self::MENU_POSITION;
        while (
            isset( $menu[ (string) $target ] )
            && self::PARENT_SLUG !== ( $menu[ (string) $target ][2] ?? '' )
        ) {
            $target += 0.00001;
        }

        $menu[ (string) $target ] = $entry;
    }

    /**
     * Ensure 系統資訊 and 聯絡我們 are the final two toolbox entries.
     */
    private static function normalize_submenu(): void {
        global $menu, $submenu;

        // 父選單不存在（例如市集停用、或本請求沒有任何 client 註冊成功）時
        // 不得建立孤兒 submenu，也不註冊無父頁的 page hook。
        $parent_exists = false;
        if ( is_array( $menu ) ) {
            foreach ( $menu as $item ) {
                if ( isset( $item[2] ) && self::PARENT_SLUG === $item[2] ) {
                    $parent_exists = true;
                    break;
                }
            }
        }
        if ( ! $parent_exists ) {
            return;
        }

        if ( ! isset( $submenu[ self::PARENT_SLUG ] ) || ! is_array( $submenu[ self::PARENT_SLUG ] ) ) {
            $submenu[ self::PARENT_SLUG ] = array();
        }

        $system_entry  = null;
        $contact_entry = null;
        $items         = array();

        foreach ( $submenu[ self::PARENT_SLUG ] as $item ) {
            $slug = isset( $item[2] ) ? (string) $item[2] : '';

            if ( self::SYSTEM_SLUG === $slug ) {
                if ( null === $system_entry ) {
                    $system_entry = $item;
                }
                continue;
            }

            if ( self::CONTACT_URL === $slug ) {
                if ( null === $contact_entry ) {
                    $contact_entry = $item;
                }
                continue;
            }

            $items[] = $item;
        }

        if ( null === $system_entry && class_exists( YSPluginHubClient::class ) ) {
            add_submenu_page(
                self::PARENT_SLUG,
                esc_html__( '系統資訊', 'ys-plugin-hub-client' ),
                esc_html__( '系統資訊', 'ys-plugin-hub-client' ),
                'manage_options',
                self::SYSTEM_SLUG,
                array( YSPluginHubClient::instance(), 'render_logs_page' )
            );

            $system_entry = array_pop( $submenu[ self::PARENT_SLUG ] );
        }

        if ( null === $contact_entry ) {
            $contact_entry = array(
                '<span id="ys-contact-link">' . esc_html__( '聯絡我們', 'ys-plugin-hub-client' ) . ' <span class="dashicons dashicons-external" style="font-size:12px;width:12px;height:12px;vertical-align:text-top;"></span></span>',
                'manage_options',
                self::CONTACT_URL,
            );
        }

        if ( is_array( $system_entry ) ) {
            $items[] = $system_entry;
        }
        $items[] = $contact_entry;

        $submenu[ self::PARENT_SLUG ] = $items;
    }
}
