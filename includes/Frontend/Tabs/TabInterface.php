<?php
/**
 * Interface for frontend profile tabs
 *
 * @package SFS\HR\Frontend\Tabs
 */

namespace SFS\HR\Frontend\Tabs;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Interface TabInterface
 *
 * All frontend profile tabs must implement this interface.
 */
interface TabInterface {
    /**
     * Render the tab content
     *
     * @param array $emp Employee data array
     * @param int   $emp_id Employee ID
     * @return void
     */
    public function render( array $emp, int $emp_id ): void;
}
