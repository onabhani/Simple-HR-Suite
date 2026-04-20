<?php
namespace SFS\HR\Modules\Automation\Services;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use SFS\HR\Core\Helpers;

class Automation_Rule_Service {

    private static function rules_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'sfs_hr_automation_rules';
    }

    private static function logs_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'sfs_hr_automation_logs';
    }

    public static function list_rules( array $args = [] ): array {
        global $wpdb;
        $table  = self::rules_table();
        $where  = [ '1=1' ];
        $values = [];

        if ( isset( $args['is_active'] ) ) {
            $where[]  = 'is_active = %d';
            $values[] = (int) $args['is_active'];
        }
        if ( ! empty( $args['trigger_type'] ) ) {
            $where[]  = 'trigger_type = %s';
            $values[] = sanitize_text_field( $args['trigger_type'] );
        }

        $limit  = isset( $args['limit'] )  ? absint( $args['limit'] )  : 50;
        $offset = isset( $args['offset'] ) ? absint( $args['offset'] ) : 0;

        $sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where )
             . " ORDER BY priority ASC, id ASC LIMIT %d OFFSET %d";
        $values[] = $limit;
        $values[] = $offset;

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );

        return array_map( [ __CLASS__, 'decode_rule' ], $rows ?: [] );
    }

    public static function get_rule( int $id ): ?array {
        global $wpdb;
        $table = self::rules_table();
        $row   = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
            ARRAY_A
        );
        return $row ? self::decode_rule( $row ) : null;
    }

    public static function save_rule( array $data ): array {
        global $wpdb;
        $table = self::rules_table();

        $name = sanitize_text_field( $data['name'] ?? '' );
        if ( '' === $name ) {
            return [ 'success' => false, 'error' => __( 'Rule name is required.', 'sfs-hr' ) ];
        }

        $valid_triggers = [ 'event', 'schedule', 'field_change' ];
        $trigger_type   = $data['trigger_type'] ?? '';
        if ( ! in_array( $trigger_type, $valid_triggers, true ) ) {
            return [ 'success' => false, 'error' => __( 'Invalid trigger type.', 'sfs-hr' ) ];
        }

        $valid_actions = [ 'notify', 'update_field', 'create_task', 'escalate' ];
        $action_type   = $data['action_type'] ?? '';
        if ( ! in_array( $action_type, $valid_actions, true ) ) {
            return [ 'success' => false, 'error' => __( 'Invalid action type.', 'sfs-hr' ) ];
        }

        $trigger_config = $data['trigger_config'] ?? [];
        $action_config  = $data['action_config'] ?? [];
        if ( is_string( $trigger_config ) ) {
            $trigger_config = json_decode( $trigger_config, true ) ?: [];
        }
        if ( is_string( $action_config ) ) {
            $action_config = json_decode( $action_config, true ) ?: [];
        }

        $now = Helpers::now_mysql();
        $row = [
            'name'           => $name,
            'description'    => isset( $data['description'] ) ? sanitize_textarea_field( $data['description'] ) : null,
            'trigger_type'   => $trigger_type,
            'trigger_config' => wp_json_encode( $trigger_config ),
            'action_type'    => $action_type,
            'action_config'  => wp_json_encode( $action_config ),
            'is_active'      => isset( $data['is_active'] ) ? (int) (bool) $data['is_active'] : 1,
            'priority'       => isset( $data['priority'] ) ? absint( $data['priority'] ) : 100,
            'updated_at'     => $now,
        ];
        $format = [ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ];

        $id = isset( $data['id'] ) ? absint( $data['id'] ) : 0;

        if ( $id && self::get_rule( $id ) ) {
            $wpdb->update( $table, $row, [ 'id' => $id ], $format, [ '%d' ] );
            return [ 'success' => true, 'id' => $id ];
        }

        $row['created_by'] = get_current_user_id();
        $row['created_at'] = $now;
        $format[]          = '%d';
        $format[]          = '%s';

        $wpdb->insert( $table, $row, $format );
        return $wpdb->insert_id
            ? [ 'success' => true, 'id' => (int) $wpdb->insert_id ]
            : [ 'success' => false, 'error' => __( 'Failed to save rule.', 'sfs-hr' ) ];
    }

    public static function delete_rule( int $id ): bool {
        global $wpdb;
        $wpdb->delete( self::logs_table(), [ 'rule_id' => $id ], [ '%d' ] );
        return false !== $wpdb->delete( self::rules_table(), [ 'id' => $id ], [ '%d' ] );
    }

    public static function evaluate_event( string $event, array $context ): void {
        $rules = self::list_rules( [ 'is_active' => 1, 'trigger_type' => 'event', 'limit' => 500 ] );

        foreach ( $rules as $rule ) {
            $cfg = $rule['trigger_config'];
            if ( ( $cfg['event'] ?? '' ) !== $event ) {
                continue;
            }
            self::execute_action( $rule, $context );
        }
    }

    public static function evaluate_scheduled(): void {
        $rules = self::list_rules( [ 'is_active' => 1, 'trigger_type' => 'schedule', 'limit' => 500 ] );

        foreach ( $rules as $rule ) {
            $transient_key = 'sfs_hr_auto_rule_' . $rule['id'] . '_last_run';
            if ( get_transient( $transient_key ) ) {
                continue;
            }

            $cfg      = $rule['trigger_config'];
            $schedule = $cfg['schedule'] ?? 'daily';

            if ( 'daily' === $schedule ) {
                $run_time = $cfg['time'] ?? '00:00';
                $now_time = current_time( 'H:i' );
                if ( $now_time < $run_time ) {
                    continue;
                }
            }

            set_transient( $transient_key, time(), DAY_IN_SECONDS );
            self::execute_action( $rule, [ 'scheduled' => true ] );
        }
    }

    public static function evaluate_field_change(
        string $entity,
        string $field,
        mixed $old_value,
        mixed $new_value,
        int $entity_id
    ): void {
        $rules = self::list_rules( [ 'is_active' => 1, 'trigger_type' => 'field_change', 'limit' => 500 ] );

        foreach ( $rules as $rule ) {
            $cfg = $rule['trigger_config'];
            if ( ( $cfg['entity'] ?? '' ) !== $entity || ( $cfg['field'] ?? '' ) !== $field ) {
                continue;
            }

            $from = $cfg['from'] ?? '*';
            $to   = $cfg['to'] ?? '*';
            if ( '*' !== $from && (string) $old_value !== (string) $from ) {
                continue;
            }
            if ( '*' !== $to && (string) $new_value !== (string) $to ) {
                continue;
            }

            $context = [
                'entity'    => $entity,
                'field'     => $field,
                'old_value' => $old_value,
                'new_value' => $new_value,
                'entity_id' => $entity_id,
            ];

            if ( 'employee' === $entity ) {
                $context['employee_id'] = $entity_id;
            }

            self::execute_action( $rule, $context );
        }
    }

    public static function execute_action( array $rule, array $context ): void {
        $action_type = $rule['action_type'];
        $cfg         = $rule['action_config'];
        $employee_id = $context['employee_id'] ?? null;

        try {
            switch ( $action_type ) {
                case 'notify':
                    $recipients = self::resolve_recipients( $cfg['recipients'] ?? [], $employee_id );
                    $template   = sanitize_text_field( $cfg['template'] ?? '' );
                    $subject    = sanitize_text_field( $cfg['subject'] ?? __( 'Automation Notification', 'sfs-hr' ) );
                    $body       = sanitize_textarea_field( $cfg['body'] ?? '' ) ?: sprintf(
                        /* translators: %s: rule name */
                        __( 'Automation rule "%s" was triggered.', 'sfs-hr' ),
                        $rule['name']
                    );

                    if ( $recipients ) {
                        Helpers::send_mail( $recipients, $subject, $body );
                    }

                    self::log_execution(
                        (int) $rule['id'], $rule['trigger_type'], $context,
                        $action_type, [ 'recipients' => $recipients, 'template' => $template ],
                        'success', null, $employee_id ? (int) $employee_id : null
                    );
                    break;

                case 'update_field':
                    $allowed_fields = [
                        'status', 'position', 'dept_id', 'branch_id',
                        'grade', 'employment_type', 'probation_end_date',
                        'contract_end_date', 'notes',
                    ];
                    $target_entity = sanitize_text_field( $cfg['entity'] ?? 'employee' );
                    $target_field  = sanitize_key( $cfg['field'] ?? '' );
                    $target_value  = sanitize_text_field( $cfg['value'] ?? '' );
                    $target_id     = (int) ( $context['entity_id'] ?? $employee_id ?? 0 );

                    if ( ! in_array( $target_field, $allowed_fields, true ) ) {
                        self::log_execution(
                            (int) $rule['id'], $rule['trigger_type'], $context,
                            $action_type, [ 'field' => $target_field ],
                            'failed', __( 'Field not in automation allowlist.', 'sfs-hr' ),
                            $employee_id ? (int) $employee_id : null
                        );
                        break;
                    }

                    if ( $target_field && $target_id && 'employee' === $target_entity ) {
                        global $wpdb;
                        $emp_table = $wpdb->prefix . 'sfs_hr_employees';
                        $wpdb->update(
                            $emp_table,
                            [ $target_field => $target_value, 'updated_at' => Helpers::now_mysql() ],
                            [ 'id' => $target_id ],
                            [ '%s', '%s' ],
                            [ '%d' ]
                        );
                    }

                    self::log_execution(
                        (int) $rule['id'], $rule['trigger_type'], $context,
                        $action_type,
                        [ 'entity' => $target_entity, 'field' => $target_field, 'value' => $target_value, 'id' => $target_id ],
                        'success', null, $employee_id ? (int) $employee_id : null
                    );
                    break;

                case 'create_task':
                    $task_data = [
                        'rule_id'     => $rule['id'],
                        'rule_name'   => $rule['name'],
                        'employee_id' => $employee_id,
                        'config'      => $cfg,
                        'context'     => $context,
                    ];

                    do_action( 'sfs_hr_automation_task_created', $task_data );

                    self::log_execution(
                        (int) $rule['id'], $rule['trigger_type'], $context,
                        $action_type, $task_data,
                        'success', null, $employee_id ? (int) $employee_id : null
                    );
                    break;

                case 'escalate':
                    $manager_email = self::get_employee_manager_email( $employee_id ? (int) $employee_id : 0 );
                    $subject       = sanitize_text_field( $cfg['subject'] ?? __( 'Escalation Notice', 'sfs-hr' ) );
                    $body          = sanitize_textarea_field( $cfg['body'] ?? '' ) ?: sprintf(
                        /* translators: %s: rule name */
                        __( 'Escalation from automation rule "%s".', 'sfs-hr' ),
                        $rule['name']
                    );

                    $status = 'skipped';
                    $error  = null;
                    if ( $manager_email ) {
                        Helpers::send_mail( $manager_email, $subject, $body );
                        $status = 'success';
                    } else {
                        $error = __( 'No manager found for employee.', 'sfs-hr' );
                    }

                    self::log_execution(
                        (int) $rule['id'], $rule['trigger_type'], $context,
                        $action_type, [ 'manager_email' => $manager_email ],
                        $status, $error, $employee_id ? (int) $employee_id : null
                    );
                    break;
            }
        } catch ( \Throwable $e ) {
            self::log_execution(
                (int) $rule['id'], $rule['trigger_type'], $context,
                $action_type, null,
                'failed', $e->getMessage(), $employee_id ? (int) $employee_id : null
            );
        }
    }

    public static function log_execution(
        int $rule_id,
        string $trigger_type,
        ?array $trigger_data,
        string $action_type,
        ?array $action_result,
        string $status,
        ?string $error,
        ?int $employee_id
    ): void {
        global $wpdb;
        $wpdb->insert( self::logs_table(), [
            'rule_id'       => $rule_id,
            'trigger_type'  => $trigger_type,
            'trigger_data'  => $trigger_data ? wp_json_encode( $trigger_data ) : null,
            'action_type'   => $action_type,
            'action_result' => $action_result ? wp_json_encode( $action_result ) : null,
            'status'        => $status,
            'error_message' => $error,
            'employee_id'   => $employee_id,
            'executed_at'   => Helpers::now_mysql(),
        ], [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ] );
    }

    public static function get_logs( array $args = [] ): array {
        global $wpdb;
        $table  = self::logs_table();
        $where  = [ '1=1' ];
        $values = [];

        if ( ! empty( $args['rule_id'] ) ) {
            $where[]  = 'rule_id = %d';
            $values[] = absint( $args['rule_id'] );
        }
        if ( ! empty( $args['status'] ) ) {
            $where[]  = 'status = %s';
            $values[] = sanitize_text_field( $args['status'] );
        }
        if ( ! empty( $args['employee_id'] ) ) {
            $where[]  = 'employee_id = %d';
            $values[] = absint( $args['employee_id'] );
        }
        if ( ! empty( $args['date_from'] ) ) {
            $where[]  = 'executed_at >= %s';
            $values[] = sanitize_text_field( $args['date_from'] );
        }
        if ( ! empty( $args['date_to'] ) ) {
            $where[]  = 'executed_at <= %s';
            $values[] = sanitize_text_field( $args['date_to'] );
        }

        $limit  = isset( $args['limit'] )  ? absint( $args['limit'] )  : 50;
        $offset = isset( $args['offset'] ) ? absint( $args['offset'] ) : 0;

        $sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where )
             . " ORDER BY executed_at DESC LIMIT %d OFFSET %d";
        $values[] = $limit;
        $values[] = $offset;

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );

        return array_map( static function ( array $row ): array {
            $row['trigger_data']  = $row['trigger_data']  ? json_decode( $row['trigger_data'], true )  : null;
            $row['action_result'] = $row['action_result'] ? json_decode( $row['action_result'], true ) : null;
            return $row;
        }, $rows ?: [] );
    }

    public static function dry_run( int $rule_id ): array {
        $rule = self::get_rule( $rule_id );
        if ( ! $rule ) {
            return [ 'success' => false, 'error' => __( 'Rule not found.', 'sfs-hr' ) ];
        }

        $result = [
            'rule_id'      => $rule_id,
            'name'         => $rule['name'],
            'trigger_type' => $rule['trigger_type'],
            'action_type'  => $rule['action_type'],
            'would_match'  => false,
            'details'      => [],
        ];

        switch ( $rule['trigger_type'] ) {
            case 'event':
                $result['details']['event'] = $rule['trigger_config']['event'] ?? '';
                $result['details']['note']  = __( 'Will fire when the specified event occurs.', 'sfs-hr' );
                break;

            case 'schedule':
                $cfg       = $rule['trigger_config'];
                $transient = 'sfs_hr_auto_rule_' . $rule_id . '_last_run';
                $last_run  = get_transient( $transient );
                $result['details']['schedule']  = $cfg['schedule'] ?? 'daily';
                $result['details']['time']      = $cfg['time'] ?? '00:00';
                $result['details']['last_run']  = $last_run ? gmdate( 'Y-m-d H:i:s', (int) $last_run ) : null;
                $result['details']['would_run'] = ! $last_run;
                $result['would_match']          = ! $last_run;
                break;

            case 'field_change':
                $cfg = $rule['trigger_config'];
                $result['details']['entity'] = $cfg['entity'] ?? '';
                $result['details']['field']  = $cfg['field'] ?? '';
                $result['details']['from']   = $cfg['from'] ?? '*';
                $result['details']['to']     = $cfg['to'] ?? '*';
                $result['details']['note']   = __( 'Will fire when the specified field changes matching from/to conditions.', 'sfs-hr' );
                break;
        }

        if ( 'notify' === $rule['action_type'] || 'escalate' === $rule['action_type'] ) {
            $recipients = $rule['action_config']['recipients'] ?? [];
            $result['details']['resolved_recipients_sample'] = self::resolve_recipients( $recipients, null );
        }

        return [ 'success' => true, 'simulation' => $result ];
    }

    private static function decode_rule( array $row ): array {
        $row['trigger_config'] = json_decode( $row['trigger_config'] ?? '{}', true ) ?: [];
        $row['action_config']  = json_decode( $row['action_config'] ?? '{}', true ) ?: [];
        return $row;
    }

    private static function resolve_recipients( array $types, ?int $employee_id ): array {
        $emails = [];

        foreach ( $types as $type ) {
            switch ( $type ) {
                case 'employee':
                    if ( $employee_id ) {
                        $emp = Helpers::get_employee_row( $employee_id );
                        if ( $emp && ! empty( $emp['user_id'] ) ) {
                            $user = get_userdata( (int) $emp['user_id'] );
                            if ( $user ) {
                                $emails[] = $user->user_email;
                            }
                        }
                    }
                    break;

                case 'manager':
                    if ( $employee_id ) {
                        $mgr_email = self::get_employee_manager_email( $employee_id );
                        if ( $mgr_email ) {
                            $emails[] = $mgr_email;
                        }
                    }
                    break;

                case 'hr':
                    $hr_users = get_users( [ 'capability' => 'sfs_hr.manage' ] );
                    foreach ( $hr_users as $u ) {
                        $emails[] = $u->user_email;
                    }
                    break;

                default:
                    if ( is_email( $type ) ) {
                        $emails[] = sanitize_email( $type );
                    }
                    break;
            }
        }

        return array_unique( array_filter( $emails ) );
    }

    private static function get_employee_manager_email( int $employee_id ): ?string {
        if ( ! $employee_id ) {
            return null;
        }

        global $wpdb;
        $emp = Helpers::get_employee_row( $employee_id );
        if ( ! $emp || empty( $emp['dept_id'] ) ) {
            return null;
        }

        $dept_table      = $wpdb->prefix . 'sfs_hr_departments';
        $manager_user_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT manager_user_id FROM {$dept_table} WHERE id = %d",
            (int) $emp['dept_id']
        ) );

        if ( $manager_user_id ) {
            $user = get_userdata( (int) $manager_user_id );
            return $user ? $user->user_email : null;
        }

        return null;
    }
}
