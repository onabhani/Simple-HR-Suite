<?php
namespace SFS\HR\Modules\Assets\Rest;

if ( ! defined('ABSPATH') ) { exit; }

/**
 * Assets REST API.
 *
 * The asset management workflows (assign, return, approve) are currently
 * handled via WordPress admin-post.php handlers in Admin/class-admin-pages.php.
 * REST endpoints will be registered here once the API layer is implemented.
 */
class Assets_REST {

    const NS = 'sfs-hr/v1';

    public function hooks(): void {
        // REST routes intentionally not registered — handlers are not yet
        // implemented.  All asset operations use admin-post.php actions
        // (see Admin/class-admin-pages.php).  Registering stub routes would
        // expose publicly-accessible endpoints that return empty responses.
    }
}
