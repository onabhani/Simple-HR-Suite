<?php
/**
 * Navigation Registry — defines all frontend portal tabs with role-based filtering.
 *
 * Tabs are grouped into sections: personal, team, org, system.
 * Each tab specifies which roles can see it and optional visibility conditions.
 *
 * @package SFS\HR\Frontend
 */

namespace SFS\HR\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Navigation {

    /**
     * All available tabs with metadata.
     *
     * @return array[] Tab definitions.
     */
    private static function all_tabs(): array {
        return [
            // ── Personal section ──────────────────────────────────────
            [
                'slug'    => 'overview',
                'label'   => __( 'Overview', 'sfs-hr' ),
                'icon'    => 'home',
                'roles'   => [ 'employee', 'trainee', 'manager', 'hr', 'gm', 'admin' ],
                'section' => 'personal',
            ],
            [
                'slug'      => 'profile',
                'label'     => __( 'Profile', 'sfs-hr' ),
                'icon'      => 'user',
                'roles'     => [ 'employee', 'trainee', 'manager', 'hr', 'gm', 'admin' ],
                'section'   => 'personal',
            ],
            [
                'slug'      => 'leave',
                'label'     => __( 'Leave', 'sfs-hr' ),
                'icon'      => 'calendar',
                'roles'     => [ 'employee', 'manager', 'hr', 'gm', 'admin' ],
                'section'   => 'personal',
                'condition' => 'not_limited',
            ],
            [
                'slug'      => 'loans',
                'label'     => __( 'Loans', 'sfs-hr' ),
                'icon'      => 'dollar',
                'roles'     => [ 'employee', 'manager', 'hr', 'gm', 'admin' ],
                'section'   => 'personal',
                'condition' => 'not_limited',
            ],
            [
                'slug'    => 'resignation',
                'label'   => __( 'Resignation', 'sfs-hr' ),
                'icon'    => 'logout',
                'roles'   => [ 'employee', 'trainee', 'manager', 'hr', 'gm', 'admin' ],
                'section' => 'personal',
            ],
            [
                'slug'      => 'settlement',
                'label'     => __( 'Settlement', 'sfs-hr' ),
                'icon'      => 'file-text',
                'roles'     => [ 'employee', 'manager', 'hr', 'gm', 'admin' ],
                'section'   => 'personal',
                'condition' => 'has_settlements',
            ],
            [
                'slug'      => 'attendance',
                'label'     => __( 'Attendance', 'sfs-hr' ),
                'icon'      => 'clock',
                'roles'     => [ 'employee', 'trainee', 'manager', 'hr', 'gm', 'admin' ],
                'section'   => 'personal',
                'condition' => 'can_self_clock',
            ],
            [
                'slug'      => 'documents',
                'label'     => __( 'Documents', 'sfs-hr' ),
                'icon'      => 'folder',
                'roles'     => [ 'employee', 'manager', 'hr', 'gm', 'admin' ],
                'section'   => 'personal',
                'condition' => 'not_limited',
            ],

            // ── Team section (Phase 3 — renderers not yet available) ──
            [
                'slug'    => 'team',
                'label'   => __( 'My Team', 'sfs-hr' ),
                'icon'    => 'users',
                'roles'   => [ 'manager', 'hr', 'gm', 'admin' ],
                'section' => 'team',
            ],
            [
                'slug'    => 'approvals',
                'label'   => __( 'Approvals', 'sfs-hr' ),
                'icon'    => 'check-circle',
                'roles'   => [ 'manager', 'hr', 'gm', 'admin' ],
                'section' => 'team',
            ],
            [
                'slug'    => 'team-attendance',
                'label'   => __( 'Team Attendance', 'sfs-hr' ),
                'icon'    => 'bar-chart',
                'roles'   => [ 'manager', 'hr', 'gm', 'admin' ],
                'section' => 'team',
            ],

            // ── Org section (Phase 4 — renderers not yet available) ──
            [
                'slug'    => 'dashboard',
                'label'   => __( 'Dashboard', 'sfs-hr' ),
                'icon'    => 'grid',
                'roles'   => [ 'hr', 'gm', 'admin' ],
                'section' => 'org',
            ],
            [
                'slug'    => 'employees',
                'label'   => __( 'Employees', 'sfs-hr' ),
                'icon'    => 'briefcase',
                'roles'   => [ 'hr', 'gm', 'admin' ],
                'section' => 'org',
            ],
        ];
    }

    /**
     * Get filtered, ordered tabs for a role.
     *
     * @param string $role       User role (admin, gm, hr, manager, employee, trainee).
     * @param array  $conditions Key-value flags for conditional visibility.
     *                           e.g. ['has_settlements' => true, 'can_self_clock' => false, 'not_limited' => true]
     * @return array[] Filtered tab definitions.
     */
    public static function get_tabs_for_role( string $role, array $conditions = [] ): array {
        $tabs = [];

        foreach ( self::all_tabs() as $tab ) {
            // Role check.
            if ( ! in_array( $role, $tab['roles'], true ) ) {
                continue;
            }

            // Condition check (if a condition is specified, it must be truthy).
            if ( ! empty( $tab['condition'] ) ) {
                if ( empty( $conditions[ $tab['condition'] ] ) ) {
                    continue;
                }
            }

            // Skip tabs that don't have a renderer yet (Phase 3/4 placeholders).
            if ( ! self::tab_has_renderer( $tab['slug'] ) ) {
                continue;
            }

            $tabs[] = $tab;
        }

        return $tabs;
    }

    /**
     * Check if a tab has a working renderer.
     *
     * During Phase 1, only existing employee tabs are available.
     * New tabs will be added here as they are implemented.
     *
     * @param string $slug Tab slug.
     * @return bool
     */
    private static function tab_has_renderer( string $slug ): bool {
        static $available = [
            'overview',
            'profile',
            'leave',
            'loans',
            'resignation',
            'settlement',
            'attendance',
            'documents',
            // Phase 3 — Team tabs.
            'team',
            'approvals',
            'team-attendance',
            // Phase 4 — Org tabs.
            'dashboard',
            'employees',
        ];
        return in_array( $slug, $available, true );
    }

    /**
     * Get SVG icon markup for a tab icon name.
     *
     * @param string $icon Icon name (matches Feather icon names).
     * @return string SVG markup.
     */
    public static function get_icon_svg( string $icon ): string {
        $icons = [
            'home'         => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline>',
            'user'         => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle>',
            'calendar'     => '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line>',
            'dollar'       => '<line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>',
            'logout'       => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line>',
            'file-text'    => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line>',
            'clock'        => '<circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline>',
            'folder'       => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path>',
            'users'        => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path>',
            'check-circle' => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline>',
            'bar-chart'    => '<line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line>',
            'grid'         => '<rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect>',
            'briefcase'    => '<rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path>',
            'more'         => '<circle cx="12" cy="12" r="1"></circle><circle cx="12" cy="5" r="1"></circle><circle cx="12" cy="19" r="1"></circle>',
        ];

        $path = $icons[ $icon ] ?? $icons['user'];

        return '<svg class="sfs-hr-tab-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
            . $path . '</svg>';
    }

    /**
     * Section labels for grouping in the sidebar.
     *
     * @return array<string,string> Section slug → translated label.
     */
    public static function section_labels(): array {
        return [
            'personal' => __( 'Personal', 'sfs-hr' ),
            'team'     => __( 'Team', 'sfs-hr' ),
            'org'      => __( 'Organization', 'sfs-hr' ),
            'system'   => __( 'System', 'sfs-hr' ),
        ];
    }

    /**
     * Render the sidebar navigation HTML (desktop).
     *
     * @param array  $tabs       Filtered tabs from get_tabs_for_role().
     * @param string $active_tab Currently active tab slug.
     * @param string $base_url   Base page URL for building tab links.
     * @return string HTML markup.
     */
    public static function render_sidebar( array $tabs, string $active_tab, string $base_url ): string {
        if ( empty( $tabs ) ) {
            return '';
        }

        $sections       = self::section_labels();
        $current_section = '';
        $html            = '<nav class="sfs-hr-sidebar" role="navigation" aria-label="' . esc_attr__( 'HR Portal Navigation', 'sfs-hr' ) . '">';

        foreach ( $tabs as $tab ) {
            $section = $tab['section'] ?? 'personal';

            // Section divider.
            if ( $section !== $current_section ) {
                if ( $current_section !== '' ) {
                    $html .= '</div>'; // close previous section
                }
                $html .= '<div class="sfs-hr-sidebar-section" data-section="' . esc_attr( $section ) . '">';
                if ( $section !== 'personal' ) {
                    $html .= '<div class="sfs-hr-sidebar-section-label">'
                        . esc_html( $sections[ $section ] ?? ucfirst( $section ) )
                        . '</div>';
                }
                $current_section = $section;
            }

            $url       = add_query_arg( 'sfs_hr_tab', $tab['slug'], $base_url );
            $is_active = ( $active_tab === $tab['slug'] );
            $classes   = 'sfs-hr-sidebar-item' . ( $is_active ? ' sfs-hr-sidebar-item--active' : '' );

            $html .= '<a href="' . esc_url( $url ) . '" class="' . $classes . '" data-tab="' . esc_attr( $tab['slug'] ) . '">'
                . self::get_icon_svg( $tab['icon'] )
                . '<span class="sfs-hr-sidebar-label">' . esc_html( $tab['label'] ) . '</span>'
                . '</a>';
        }

        if ( $current_section !== '' ) {
            $html .= '</div>'; // close last section
        }

        $html .= '</nav>';

        return $html;
    }

    /**
     * Render the mobile bottom tab bar HTML.
     *
     * Shows up to $max_visible tabs, with a "More" menu for the rest.
     *
     * @param array  $tabs       Filtered tabs from get_tabs_for_role().
     * @param string $active_tab Currently active tab slug.
     * @param string $base_url   Base page URL for building tab links.
     * @param int    $max_visible Maximum tabs visible before "More" (default 5).
     * @return string HTML markup.
     */
    public static function render_bottom_bar( array $tabs, string $active_tab, string $base_url, int $max_visible = 5 ): string {
        if ( empty( $tabs ) ) {
            return '';
        }

        $visible_tabs  = array_slice( $tabs, 0, $max_visible );
        $overflow_tabs = array_slice( $tabs, $max_visible );
        $has_overflow  = ! empty( $overflow_tabs );

        // If active tab is in overflow, swap it into the visible set.
        if ( $has_overflow ) {
            $active_in_overflow = false;
            foreach ( $overflow_tabs as $i => $otab ) {
                if ( $otab['slug'] === $active_tab ) {
                    $active_in_overflow = true;
                    // Swap: move the last visible tab to overflow, bring active tab into visible.
                    $swap_out                      = array_pop( $visible_tabs );
                    $visible_tabs[]                = $otab;
                    $overflow_tabs[ $i ]           = $swap_out;
                    break;
                }
            }
            // Re-check overflow after swap.
            $overflow_tabs = array_values( $overflow_tabs );
            $has_overflow  = ! empty( $overflow_tabs );
        }

        $html = '<div class="sfs-hr-profile-tabs">';

        foreach ( $visible_tabs as $tab ) {
            $url       = add_query_arg( 'sfs_hr_tab', $tab['slug'], $base_url );
            $is_active = ( $active_tab === $tab['slug'] );
            $classes   = 'sfs-hr-tab' . ( $is_active ? ' sfs-hr-tab-active' : '' );

            $html .= '<a href="' . esc_url( $url ) . '" class="' . $classes . '">'
                . self::get_icon_svg( $tab['icon'] )
                . '<span data-i18n-key="' . esc_attr( $tab['slug'] === 'team' ? 'my_team' : ( $tab['slug'] === 'team-attendance' ? 'team_attendance' : $tab['slug'] ) ) . '">' . esc_html( $tab['label'] ) . '</span>'
                . '</a>';
        }

        // "More" button with overflow menu.
        if ( $has_overflow ) {
            $more_active = false;
            foreach ( $overflow_tabs as $otab ) {
                if ( $otab['slug'] === $active_tab ) {
                    $more_active = true;
                    break;
                }
            }
            $more_classes = 'sfs-hr-tab sfs-hr-tab-more' . ( $more_active ? ' sfs-hr-tab-active' : '' );

            $html .= '<button type="button" class="' . $more_classes . '" aria-expanded="false" aria-haspopup="true">'
                . self::get_icon_svg( 'more' )
                . '<span data-i18n-key="more">' . esc_html__( 'More', 'sfs-hr' ) . '</span>'
                . '</button>';

            $html .= '<div class="sfs-hr-more-menu" hidden>';
            foreach ( $overflow_tabs as $otab ) {
                $url       = add_query_arg( 'sfs_hr_tab', $otab['slug'], $base_url );
                $is_active = ( $active_tab === $otab['slug'] );
                $classes   = 'sfs-hr-more-menu-item' . ( $is_active ? ' sfs-hr-more-menu-item--active' : '' );

                $html .= '<a href="' . esc_url( $url ) . '" class="' . $classes . '">'
                    . self::get_icon_svg( $otab['icon'] )
                    . '<span data-i18n-key="' . esc_attr( $otab['slug'] === 'team' ? 'my_team' : ( $otab['slug'] === 'team-attendance' ? 'team_attendance' : $otab['slug'] ) ) . '">' . esc_html( $otab['label'] ) . '</span>'
                    . '</a>';
            }
            $html .= '</div>';
        }

        $html .= '</div>';

        return $html;
    }
}
