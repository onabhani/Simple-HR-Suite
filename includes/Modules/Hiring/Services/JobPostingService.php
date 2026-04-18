<?php
namespace SFS\HR\Modules\Hiring\Services;

if (!defined('ABSPATH')) { exit; }

use SFS\HR\Core\Helpers;
use SFS\HR\Modules\Hiring\HiringModule;

/**
 * Job Posting Service
 * Business logic for job postings (CRUD, publishing, public listings)
 * and job applications (submission, status tracking, candidate conversion).
 */
class JobPostingService {

    /* ─────────────────────────────────────────────
     * Job Postings
     * ───────────────────────────────────────────── */

    /**
     * Create a new job posting (status = draft).
     *
     * @param array $data Posting data.
     * @return int|null Insert ID or null on failure.
     */
    public static function create(array $data): ?int {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_job_postings';
        $now   = current_time('mysql');

        $title = sanitize_text_field($data['title'] ?? '');
        $slug  = self::generate_unique_slug($title);

        $result = $wpdb->insert($table, [
            'requisition_id'  => isset($data['requisition_id']) ? intval($data['requisition_id']) : null,
            'title'           => $title,
            'title_ar'        => sanitize_text_field($data['title_ar'] ?? ''),
            'description'     => wp_kses_post($data['description'] ?? ''),
            'description_ar'  => wp_kses_post($data['description_ar'] ?? ''),
            'dept_id'         => intval($data['dept_id'] ?? 0),
            'location'        => sanitize_text_field($data['location'] ?? ''),
            'employment_type' => self::sanitize_employment_type($data['employment_type'] ?? 'full_time'),
            'salary_range'    => sanitize_text_field($data['salary_range'] ?? ''),
            'channel'         => self::sanitize_channel($data['channel'] ?? 'both'),
            'form_fields'     => wp_json_encode($data['form_fields'] ?? []),
            'status'          => 'draft',
            'slug'            => $slug,
            'closes_at'       => sanitize_text_field($data['closes_at'] ?? '') ?: null,
            'created_by'      => get_current_user_id(),
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        return $result ? (int) $wpdb->insert_id : null;
    }

    /**
     * Update editable fields on a posting. Blocked when status = 'archived'.
     *
     * @param int   $id   Posting ID.
     * @param array $data Fields to update.
     * @return bool
     */
    public static function update(int $id, array $data): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_job_postings';

        $existing = self::get($id);
        if (!$existing || $existing->status === 'archived') {
            return false;
        }

        $allowed = [
            'title', 'title_ar', 'description', 'description_ar',
            'dept_id', 'location', 'employment_type', 'salary_range',
            'channel', 'form_fields', 'closes_at', 'requisition_id',
        ];

        $update = [];
        foreach ($allowed as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            switch ($field) {
                case 'dept_id':
                case 'requisition_id':
                    $update[$field] = intval($data[$field]);
                    break;
                case 'description':
                case 'description_ar':
                    $update[$field] = wp_kses_post($data[$field]);
                    break;
                case 'form_fields':
                    $update[$field] = wp_json_encode($data[$field]);
                    break;
                case 'employment_type':
                    $update[$field] = self::sanitize_employment_type($data[$field]);
                    break;
                case 'channel':
                    $update[$field] = self::sanitize_channel($data[$field]);
                    break;
                case 'closes_at':
                    $update[$field] = sanitize_text_field($data[$field]) ?: null;
                    break;
                default:
                    $update[$field] = sanitize_text_field($data[$field]);
            }
        }

        if (empty($update)) {
            return false;
        }

        $update['updated_at'] = current_time('mysql');

        return (bool) $wpdb->update($table, $update, ['id' => $id]);
    }

    /**
     * Publish a draft posting.
     *
     * @param int $id Posting ID.
     * @return bool
     */
    public static function publish(int $id): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_job_postings';

        $existing = self::get($id);
        if (!$existing || $existing->status !== 'draft') {
            return false;
        }

        return (bool) $wpdb->update($table, [
            'status'       => 'published',
            'published_at' => current_time('mysql'),
            'updated_at'   => current_time('mysql'),
        ], ['id' => $id]);
    }

    /**
     * Close a published posting.
     *
     * @param int $id Posting ID.
     * @return bool
     */
    public static function close(int $id): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_job_postings';

        $existing = self::get($id);
        if (!$existing || $existing->status !== 'published') {
            return false;
        }

        return (bool) $wpdb->update($table, [
            'status'     => 'closed',
            'updated_at' => current_time('mysql'),
        ], ['id' => $id]);
    }

    /**
     * Archive a posting (any non-archived status).
     *
     * @param int $id Posting ID.
     * @return bool
     */
    public static function archive(int $id): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_job_postings';

        $existing = self::get($id);
        if (!$existing || $existing->status === 'archived') {
            return false;
        }

        return (bool) $wpdb->update($table, [
            'status'     => 'archived',
            'updated_at' => current_time('mysql'),
        ], ['id' => $id]);
    }

    /**
     * Get a single posting with department name joined.
     *
     * @param int $id Posting ID.
     * @return object|null
     */
    public static function get(int $id): ?object {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_job_postings';
        $depts = $wpdb->prefix . 'sfs_hr_departments';

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT p.*, d.name AS department_name
             FROM {$table} p
             LEFT JOIN {$depts} d ON d.id = p.dept_id
             WHERE p.id = %d",
            $id
        ));

        return $row ?: null;
    }

    /**
     * Get a published posting by its slug (for public-facing pages).
     *
     * @param string $slug URL slug.
     * @return object|null
     */
    public static function get_by_slug(string $slug): ?object {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_job_postings';
        $depts = $wpdb->prefix . 'sfs_hr_departments';

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT p.*, d.name AS department_name
             FROM {$table} p
             LEFT JOIN {$depts} d ON d.id = p.dept_id
             WHERE p.slug = %s AND p.status = 'published'",
            sanitize_text_field($slug)
        ));

        return $row ?: null;
    }

    /**
     * List postings with optional filters.
     *
     * @param array $filters Optional: status, dept_id, requisition_id.
     * @return array
     */
    public static function get_list(array $filters = []): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_job_postings';
        $depts = $wpdb->prefix . 'sfs_hr_departments';

        $where  = [];
        $values = [];

        if (!empty($filters['status'])) {
            $where[]  = 'p.status = %s';
            $values[] = sanitize_text_field($filters['status']);
        }
        if (!empty($filters['dept_id'])) {
            $where[]  = 'p.dept_id = %d';
            $values[] = intval($filters['dept_id']);
        }
        if (!empty($filters['requisition_id'])) {
            $where[]  = 'p.requisition_id = %d';
            $values[] = intval($filters['requisition_id']);
        }

        $where_clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT p.*, d.name AS department_name
                FROM {$table} p
                LEFT JOIN {$depts} d ON d.id = p.dept_id
                {$where_clause}
                ORDER BY p.created_at DESC";

        if ($values) {
            $sql = $wpdb->prepare($sql, ...$values);
        }

        return $wpdb->get_results($sql);
    }

    /**
     * Get all published postings where closes_at >= today or is NULL.
     *
     * @return array
     */
    public static function get_public_listings(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_job_postings';
        $depts = $wpdb->prefix . 'sfs_hr_departments';
        $today = current_time('Y-m-d');

        return $wpdb->get_results($wpdb->prepare(
            "SELECT p.*, d.name AS department_name
             FROM {$table} p
             LEFT JOIN {$depts} d ON d.id = p.dept_id
             WHERE p.status = 'published'
               AND (p.closes_at IS NULL OR p.closes_at >= %s)
             ORDER BY p.published_at DESC",
            $today
        ));
    }

    /* ─────────────────────────────────────────────
     * Job Applications
     * ───────────────────────────────────────────── */

    /**
     * Submit a public job application.
     *
     * Validates required fields, handles resume upload, sends acknowledgment email.
     *
     * @param int   $posting_id Posting ID.
     * @param array $data       Application data.
     * @return int|null Insert ID or null on failure.
     */
    public static function submit_application(int $posting_id, array $data): ?int {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_job_applications';

        // Posting must be published
        $posting = self::get($posting_id);
        if (!$posting || $posting->status !== 'published') {
            return null;
        }

        // Required fields
        $first_name = sanitize_text_field($data['first_name'] ?? '');
        $email      = sanitize_email($data['email'] ?? '');
        if (empty($first_name) || empty($email)) {
            return null;
        }

        // Handle resume upload
        $resume_url = '';
        if (!empty($_FILES['resume']) && !empty($_FILES['resume']['name'])) {
            if (!function_exists('media_handle_upload')) {
                require_once ABSPATH . 'wp-admin/includes/image.php';
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/media.php';
            }
            $attachment_id = media_handle_upload('resume', 0);
            if (!is_wp_error($attachment_id)) {
                $resume_url = wp_get_attachment_url($attachment_id);
            }
        }

        $now = current_time('mysql');

        $result = $wpdb->insert($table, [
            'posting_id'   => $posting_id,
            'first_name'   => $first_name,
            'last_name'    => sanitize_text_field($data['last_name'] ?? ''),
            'email'        => $email,
            'phone'        => sanitize_text_field($data['phone'] ?? ''),
            'resume_url'   => $resume_url ?: esc_url_raw($data['resume_url'] ?? ''),
            'cover_letter' => sanitize_textarea_field($data['cover_letter'] ?? ''),
            'form_data'    => wp_json_encode($data['form_data'] ?? []),
            'status'       => 'received',
            'created_at'   => $now,
        ]);

        if (!$result) {
            return null;
        }

        $application_id = (int) $wpdb->insert_id;

        // Send acknowledgment email
        Helpers::send_mail(
            $email,
            __('Application Received', 'sfs-hr'),
            sprintf(
                /* translators: %1$s: applicant first name, %2$s: job title */
                __('Dear %1$s,<br><br>Thank you for applying for the <strong>%2$s</strong> position. We have received your application and will review it shortly.<br><br>Best regards.', 'sfs-hr'),
                esc_html($first_name),
                esc_html($posting->title)
            )
        );

        return $application_id;
    }

    /**
     * Convert a job application into a candidate record.
     *
     * Creates a row in sfs_hr_candidates and links it back via candidate_id
     * on the application.
     *
     * @param int $application_id Application ID.
     * @return int|null Candidate ID or null on failure.
     */
    public static function convert_to_candidate(int $application_id): ?int {
        global $wpdb;
        $apps_table = $wpdb->prefix . 'sfs_hr_job_applications';
        $cand_table = $wpdb->prefix . 'sfs_hr_candidates';

        $app = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$apps_table} WHERE id = %d",
            $application_id
        ));

        if (!$app || !empty($app->candidate_id)) {
            return null;
        }

        // Fetch posting for position / dept info
        $posting = !empty($app->posting_id) ? self::get((int) $app->posting_id) : null;

        $now             = current_time('mysql');
        $request_number  = HiringModule::generate_candidate_reference();

        $result = $wpdb->insert($cand_table, [
            'request_number'     => $request_number,
            'first_name'         => $app->first_name,
            'last_name'          => $app->last_name,
            'email'              => $app->email,
            'phone'              => $app->phone ?? '',
            'position_applied'   => $posting ? $posting->title : '',
            'dept_id'            => $posting ? intval($posting->dept_id) : null,
            'application_source' => 'applied',
            'resume_url'         => $app->resume_url ?? '',
            'status'             => 'applied',
            'created_by'         => get_current_user_id(),
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);

        if (!$result) {
            return null;
        }

        $candidate_id = (int) $wpdb->insert_id;

        // Link candidate back to application
        $wpdb->update($apps_table, [
            'candidate_id' => $candidate_id,
        ], ['id' => $application_id]);

        return $candidate_id;
    }

    /**
     * List applications for a posting, optionally filtered by status.
     *
     * @param int         $posting_id Posting ID.
     * @param string|null $status     Optional status filter.
     * @return array
     */
    public static function get_applications(int $posting_id, ?string $status = null): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_job_applications';

        if ($status) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE posting_id = %d AND status = %s ORDER BY created_at DESC",
                $posting_id,
                sanitize_text_field($status)
            ));
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE posting_id = %d ORDER BY created_at DESC",
            $posting_id
        ));
    }

    /**
     * Update an application's status.
     *
     * @param int    $id     Application ID.
     * @param string $status New status.
     * @return bool
     */
    public static function update_application_status(int $id, string $status): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_job_applications';

        $valid = ['received', 'reviewed', 'shortlisted', 'rejected'];
        $status = sanitize_text_field($status);

        if (!in_array($status, $valid, true)) {
            return false;
        }

        $update = ['status' => $status];

        // Set acknowledged_at on first review
        if ($status === 'reviewed') {
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT acknowledged_at FROM {$table} WHERE id = %d",
                $id
            ));
            if ($existing && empty($existing->acknowledged_at)) {
                $update['acknowledged_at'] = current_time('mysql');
            }
        }

        return (bool) $wpdb->update($table, $update, ['id' => $id]);
    }

    /* ─────────────────────────────────────────────
     * Helpers
     * ───────────────────────────────────────────── */

    /**
     * Get employment type options with translated labels.
     *
     * @return array
     */
    public static function get_employment_types(): array {
        return [
            'full_time'  => __('Full Time', 'sfs-hr'),
            'part_time'  => __('Part Time', 'sfs-hr'),
            'contract'   => __('Contract', 'sfs-hr'),
            'internship' => __('Internship', 'sfs-hr'),
        ];
    }

    /**
     * Get channel options with translated labels.
     *
     * @return array
     */
    public static function get_channels(): array {
        return [
            'internal' => __('Internal', 'sfs-hr'),
            'external' => __('External', 'sfs-hr'),
            'both'     => __('Internal & External', 'sfs-hr'),
        ];
    }

    /**
     * Generate a unique slug from a title.
     *
     * @param string $title Post title.
     * @return string
     */
    private static function generate_unique_slug(string $title): string {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_job_postings';
        $base  = sanitize_title($title) ?: 'job';

        $slug   = $base;
        $suffix = 2;

        while ($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE slug = %s",
            $slug
        ))) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * Sanitize employment type to allowed value.
     *
     * @param string $type Raw value.
     * @return string
     */
    private static function sanitize_employment_type(string $type): string {
        $valid = ['full_time', 'part_time', 'contract', 'internship'];
        $type  = sanitize_text_field($type);
        return in_array($type, $valid, true) ? $type : 'full_time';
    }

    /**
     * Sanitize channel to allowed value.
     *
     * @param string $channel Raw value.
     * @return string
     */
    private static function sanitize_channel(string $channel): string {
        $valid = ['internal', 'external', 'both'];
        $channel = sanitize_text_field($channel);
        return in_array($channel, $valid, true) ? $channel : 'both';
    }
}
