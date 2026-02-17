<?php

namespace SFS\HR\Install;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Creates default WordPress pages with plugin shortcodes on first installation.
 * Stores page IDs as options so they can be referenced later and avoids duplicates on reactivation.
 */
class Pages {

    /**
     * Pages to create on activation.
     * Each entry: option_key => [ title, shortcode content, slug ].
     */
    private static array $pages = [
        'sfs_hr_portal_page_id' => [
            'title'   => 'HR Portal',
            'content' => '[sfs_hr_my_profile]',
            'slug'    => 'hr-portal',
        ],
        'sfs_hr_kiosk_page_id' => [
            'title'   => 'Attendance Kiosk',
            'content' => '[sfs_hr_kiosk]',
            'slug'    => 'attendance-kiosk',
        ],
    ];

    /**
     * Create all default pages if they don't already exist.
     */
    public static function create(): void {
        foreach ( self::$pages as $option_key => $page ) {
            self::maybe_create_page( $option_key, $page['title'], $page['content'], $page['slug'] );
        }
    }

    /**
     * Create a single page if it hasn't been created yet (idempotent).
     */
    private static function maybe_create_page( string $option_key, string $title, string $content, string $slug ): void {
        // Check if we already created this page previously
        $existing_id = get_option( $option_key );

        if ( $existing_id && get_post_status( $existing_id ) ) {
            // Page already exists (even if trashed) — do nothing
            return;
        }

        // Check if a page with this slug already exists (user may have created it manually)
        $existing_page = get_page_by_path( $slug );
        if ( $existing_page ) {
            update_option( $option_key, $existing_page->ID );
            return;
        }

        $page_id = wp_insert_post( [
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_name'    => $slug,
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
        ] );

        if ( $page_id && ! is_wp_error( $page_id ) ) {
            update_option( $option_key, $page_id );
        }
    }

    /**
     * Return the definitions array so the settings page can display page status.
     *
     * @return array<string, array{title: string, content: string, slug: string}>
     */
    public static function get_definitions(): array {
        return self::$pages;
    }
}
