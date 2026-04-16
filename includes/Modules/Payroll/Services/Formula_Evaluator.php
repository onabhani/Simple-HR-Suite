<?php
namespace SFS\HR\Modules\Payroll\Services;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Formula_Evaluator
 *
 * Safe sandboxed expression evaluator for salary component formulas and
 * applicability conditions.
 *
 * SECURITY: This is a custom tokenizer + recursive-descent parser. It does
 * NOT use eval(), create_function(), or any other dynamic-code primitive.
 * The grammar is strictly limited to arithmetic, comparison, boolean logic,
 * and a whitelist of pure mathematical helper functions. Variables are
 * resolved from an explicit context array — there is NO access to
 * globals, superglobals, classes, methods, properties, or the filesystem.
 *
 * Grammar (top-down):
 *   expression   := or_expr
 *   or_expr      := and_expr ('or' and_expr)*
 *   and_expr     := not_expr ('and' not_expr)*
 *   not_expr     := 'not' not_expr | comparison
 *   comparison   := additive ((=|!=|<|>|<=|>=) additive)?
 *   additive     := multiplicative ((+|-) multiplicative)*
 *   multiplicative := unary ((*|/|%) unary)*
 *   unary        := ('-'|'+') unary | power
 *   power        := primary ('^' unary)?          // right-assoc
 *   primary      := NUMBER | STRING | IDENT ('(' args ')')?
 *                 | '(' expression ')'
 *   args         := expression (',' expression)*
 *
 * Supported functions (pure, no side effects):
 *   min(a,b,...)   max(a,b,...)   round(x[,n])   abs(x)
 *   floor(x)       ceil(x)        if(cond, a, b)
 *
 * Constants: true = 1, false = 0
 *
 * Return value of evaluate() is always a float (booleans → 1.0/0.0).
 */
class Formula_Evaluator {

    /** @var string */
    private $src = '';

    /** @var int */
    private $pos = 0;

    /** @var array<string,mixed> */
    private $ctx = [];

    /** @var int recursion guard */
    private $depth = 0;

    private const MAX_DEPTH = 64;

    /**
     * Evaluate an expression against a context.
     *
     * @param string               $expression Formula source.
     * @param array<string,mixed>  $context    Variable bindings (scalar values only).
     *
     * @return float  Numeric result. Returns 0.0 on parse/runtime error.
     */
    public static function evaluate( string $expression, array $context = [] ): float {
        try {
            $evaluator = new self();
            return (float) $evaluator->run( $expression, $context );
        } catch ( \Throwable $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[SFS HR] Formula_Evaluator error: ' . $e->getMessage() . ' | expr=' . $expression );
            }
            return 0.0;
        }
    }

    /**
     * Validate a formula without executing it (parse-only).
     *
     * @return array{valid:bool, error:?string}
     */
    public static function validate( string $expression ): array {
        try {
            $evaluator = new self();
            // Run with a no-op context that answers any identifier request with 0.
            // Functions are still validated (wrong arity will error).
            $evaluator->src = $expression;
            $evaluator->pos = 0;
            $evaluator->ctx = [];
            $evaluator->depth = 0;
            // Parse-only mode: we still call expr() but any missing-variable
            // errors are treated as success since variables may be context-specific.
            $evaluator->parse_only = true;
            $evaluator->expr();
            $evaluator->skip_ws();
            if ( $evaluator->pos < strlen( $evaluator->src ) ) {
                return [ 'valid' => false, 'error' => sprintf( 'Unexpected token at position %d', $evaluator->pos ) ];
            }
            return [ 'valid' => true, 'error' => null ];
        } catch ( \Throwable $e ) {
            return [ 'valid' => false, 'error' => $e->getMessage() ];
        }
    }

    /** @var bool */
    private $parse_only = false;

    /**
     * @param array<string,mixed> $context
     * @return mixed
     */
    private function run( string $expression, array $context ) {
        $this->src        = $expression;
        $this->pos        = 0;
        $this->ctx        = array_change_key_case( $context, CASE_LOWER );
        $this->depth      = 0;
        $this->parse_only = false;

        $result = $this->expr();
        $this->skip_ws();
        if ( $this->pos < strlen( $this->src ) ) {
            throw new \RuntimeException( sprintf( 'Unexpected token at position %d', $this->pos ) );
        }
        return $result;
    }

    /* ---------- Grammar productions ---------- */

    private function expr() {
        $this->push_depth();
        $left = $this->or_expr();
        $this->pop_depth();
        return $left;
    }

    private function or_expr() {
        $left = $this->and_expr();
        while ( $this->match_word( 'or' ) ) {
            $right = $this->and_expr();
            $left = ( $this->truthy( $left ) || $this->truthy( $right ) ) ? 1 : 0;
        }
        return $left;
    }

    private function and_expr() {
        $left = $this->not_expr();
        while ( $this->match_word( 'and' ) ) {
            $right = $this->not_expr();
            $left = ( $this->truthy( $left ) && $this->truthy( $right ) ) ? 1 : 0;
        }
        return $left;
    }

    private function not_expr() {
        if ( $this->match_word( 'not' ) ) {
            $v = $this->not_expr();
            return $this->truthy( $v ) ? 0 : 1;
        }
        return $this->comparison();
    }

    private function comparison() {
        $left = $this->additive();
        $this->skip_ws();
        $op = null;
        // Order matters: check 2-char ops before 1-char.
        foreach ( [ '<=', '>=', '!=', '<>', '=', '<', '>' ] as $candidate ) {
            if ( substr( $this->src, $this->pos, strlen( $candidate ) ) === $candidate ) {
                // Avoid matching '=' inside '==' (we don't support '==', but be safe)
                $op = $candidate;
                $this->pos += strlen( $candidate );
                break;
            }
        }
        if ( $op === null ) {
            return $left;
        }
        $right = $this->additive();
        return $this->compare( $left, $right, $op ) ? 1 : 0;
    }

    private function additive() {
        $left = $this->multiplicative();
        while ( true ) {
            $this->skip_ws();
            $c = $this->src[ $this->pos ] ?? '';
            if ( $c === '+' || $c === '-' ) {
                $this->pos++;
                $right = $this->multiplicative();
                $left = ( $c === '+' )
                    ? ( $this->num( $left ) + $this->num( $right ) )
                    : ( $this->num( $left ) - $this->num( $right ) );
            } else {
                break;
            }
        }
        return $left;
    }

    private function multiplicative() {
        $left = $this->unary();
        while ( true ) {
            $this->skip_ws();
            $c = $this->src[ $this->pos ] ?? '';
            if ( $c === '*' || $c === '/' || $c === '%' ) {
                $this->pos++;
                $right = $this->unary();
                $l = $this->num( $left );
                $r = $this->num( $right );
                if ( $c === '*' ) {
                    $left = $l * $r;
                } elseif ( $c === '/' ) {
                    $left = ( $r == 0.0 ) ? 0 : ( $l / $r );
                } else {
                    $left = ( $r == 0.0 ) ? 0 : fmod( $l, $r );
                }
            } else {
                break;
            }
        }
        return $left;
    }

    private function unary() {
        $this->skip_ws();
        $c = $this->src[ $this->pos ] ?? '';
        if ( $c === '-' ) {
            $this->pos++;
            return -1 * $this->num( $this->unary() );
        }
        if ( $c === '+' ) {
            $this->pos++;
            return $this->num( $this->unary() );
        }
        return $this->power();
    }

    private function power() {
        $base = $this->primary();
        $this->skip_ws();
        if ( ( $this->src[ $this->pos ] ?? '' ) === '^' ) {
            $this->pos++;
            $exp = $this->unary(); // right-associative
            return pow( $this->num( $base ), $this->num( $exp ) );
        }
        return $base;
    }

    private function primary() {
        $this->skip_ws();
        $c = $this->src[ $this->pos ] ?? '';

        if ( $c === '' ) {
            throw new \RuntimeException( 'Unexpected end of expression' );
        }

        // Parenthesized expression
        if ( $c === '(' ) {
            $this->pos++;
            $this->push_depth();
            $v = $this->expr();
            $this->pop_depth();
            $this->skip_ws();
            if ( ( $this->src[ $this->pos ] ?? '' ) !== ')' ) {
                throw new \RuntimeException( sprintf( 'Missing closing paren at %d', $this->pos ) );
            }
            $this->pos++;
            return $v;
        }

        // Number literal
        if ( ctype_digit( $c ) || $c === '.' ) {
            return $this->read_number();
        }

        // String literal
        if ( $c === "'" || $c === '"' ) {
            return $this->read_string();
        }

        // Identifier (variable, function, or keyword 'true'/'false')
        if ( ctype_alpha( $c ) || $c === '_' ) {
            $ident = $this->read_ident();
            $lower = strtolower( $ident );

            // Constants
            if ( $lower === 'true' )  { return 1; }
            if ( $lower === 'false' ) { return 0; }
            if ( $lower === 'null' )  { return 0; }

            // Function call?
            $this->skip_ws();
            if ( ( $this->src[ $this->pos ] ?? '' ) === '(' ) {
                $this->pos++;
                $args = [];
                $this->skip_ws();
                if ( ( $this->src[ $this->pos ] ?? '' ) !== ')' ) {
                    $this->push_depth();
                    $args[] = $this->expr();
                    $this->skip_ws();
                    while ( ( $this->src[ $this->pos ] ?? '' ) === ',' ) {
                        $this->pos++;
                        $args[] = $this->expr();
                        $this->skip_ws();
                    }
                    $this->pop_depth();
                }
                if ( ( $this->src[ $this->pos ] ?? '' ) !== ')' ) {
                    throw new \RuntimeException( sprintf( 'Missing closing paren in call to %s at %d', $ident, $this->pos ) );
                }
                $this->pos++;
                return $this->call_function( $lower, $args );
            }

            // Variable lookup
            if ( $this->parse_only ) {
                return 0;
            }
            if ( ! array_key_exists( $lower, $this->ctx ) ) {
                // Unknown variable → treat as 0 for resilience.
                return 0;
            }
            return $this->ctx[ $lower ];
        }

        throw new \RuntimeException( sprintf( 'Unexpected character %s at %d', $c, $this->pos ) );
    }

    /* ---------- Function registry ---------- */

    /**
     * @param array<int,mixed> $args
     * @return mixed
     */
    private function call_function( string $name, array $args ) {
        switch ( $name ) {
            case 'min':
                if ( empty( $args ) ) { throw new \RuntimeException( 'min() needs at least one arg' ); }
                return min( array_map( [ $this, 'num' ], $args ) );
            case 'max':
                if ( empty( $args ) ) { throw new \RuntimeException( 'max() needs at least one arg' ); }
                return max( array_map( [ $this, 'num' ], $args ) );
            case 'round':
                $x = isset( $args[0] ) ? $this->num( $args[0] ) : 0;
                $n = isset( $args[1] ) ? (int) $this->num( $args[1] ) : 0;
                return round( $x, $n );
            case 'abs':
                return abs( $this->num( $args[0] ?? 0 ) );
            case 'floor':
                return floor( $this->num( $args[0] ?? 0 ) );
            case 'ceil':
                return ceil( $this->num( $args[0] ?? 0 ) );
            case 'if':
                if ( count( $args ) !== 3 ) {
                    throw new \RuntimeException( 'if(cond,a,b) requires 3 args' );
                }
                return $this->truthy( $args[0] ) ? $args[1] : $args[2];
            default:
                throw new \RuntimeException( 'Unknown function: ' . $name );
        }
    }

    /* ---------- Lexer helpers ---------- */

    private function skip_ws(): void {
        $len = strlen( $this->src );
        while ( $this->pos < $len ) {
            $c = $this->src[ $this->pos ];
            if ( $c === ' ' || $c === "\t" || $c === "\n" || $c === "\r" ) {
                $this->pos++;
            } else {
                break;
            }
        }
    }

    /**
     * Consume a bareword keyword only when it's followed by a non-identifier
     * character (so "order" doesn't match "or").
     */
    private function match_word( string $word ): bool {
        $this->skip_ws();
        $len = strlen( $word );
        if ( strcasecmp( substr( $this->src, $this->pos, $len ), $word ) !== 0 ) {
            return false;
        }
        $next = $this->src[ $this->pos + $len ] ?? '';
        if ( $next !== '' && ( ctype_alnum( $next ) || $next === '_' ) ) {
            return false;
        }
        $this->pos += $len;
        return true;
    }

    private function read_number(): float {
        $start = $this->pos;
        $len   = strlen( $this->src );
        $seen_dot = false;
        while ( $this->pos < $len ) {
            $c = $this->src[ $this->pos ];
            if ( ctype_digit( $c ) ) {
                $this->pos++;
            } elseif ( $c === '.' && ! $seen_dot ) {
                $seen_dot = true;
                $this->pos++;
            } else {
                break;
            }
        }
        $num = substr( $this->src, $start, $this->pos - $start );
        if ( $num === '' || $num === '.' ) {
            throw new \RuntimeException( 'Invalid numeric literal' );
        }
        return (float) $num;
    }

    private function read_string(): string {
        $quote = $this->src[ $this->pos ];
        $this->pos++;
        $start = $this->pos;
        $len   = strlen( $this->src );
        while ( $this->pos < $len && $this->src[ $this->pos ] !== $quote ) {
            $this->pos++;
        }
        if ( $this->pos >= $len ) {
            throw new \RuntimeException( 'Unterminated string literal' );
        }
        $s = substr( $this->src, $start, $this->pos - $start );
        $this->pos++; // consume closing quote
        return $s;
    }

    private function read_ident(): string {
        $start = $this->pos;
        $len   = strlen( $this->src );
        while ( $this->pos < $len ) {
            $c = $this->src[ $this->pos ];
            if ( ctype_alnum( $c ) || $c === '_' || $c === '.' ) {
                $this->pos++;
            } else {
                break;
            }
        }
        return substr( $this->src, $start, $this->pos - $start );
    }

    /* ---------- Value helpers ---------- */

    /**
     * Coerce a value to float for arithmetic.
     * @param mixed $v
     */
    private function num( $v ): float {
        if ( is_bool( $v ) )   { return $v ? 1.0 : 0.0; }
        if ( is_int( $v ) )    { return (float) $v; }
        if ( is_float( $v ) )  { return $v; }
        if ( is_string( $v ) ) { return is_numeric( $v ) ? (float) $v : 0.0; }
        return 0.0;
    }

    /**
     * @param mixed $v
     */
    private function truthy( $v ): bool {
        if ( is_bool( $v ) )   { return $v; }
        if ( is_int( $v ) )    { return $v !== 0; }
        if ( is_float( $v ) )  { return $v != 0.0; }
        if ( is_string( $v ) ) { return $v !== '' && $v !== '0'; }
        return (bool) $v;
    }

    /**
     * Compare two values by operator. Strings compare by equality only.
     * @param mixed $l
     * @param mixed $r
     */
    private function compare( $l, $r, string $op ): bool {
        $string_compare = is_string( $l ) || is_string( $r );

        if ( $string_compare && ( $op === '=' || $op === '!=' || $op === '<>' ) ) {
            $sl = is_string( $l ) ? $l : (string) $this->num( $l );
            $sr = is_string( $r ) ? $r : (string) $this->num( $r );
            $eq = ( $sl === $sr );
            return ( $op === '=' ) ? $eq : ! $eq;
        }

        $a = $this->num( $l );
        $b = $this->num( $r );
        switch ( $op ) {
            case '=':   return $a == $b;
            case '!=':
            case '<>':  return $a != $b;
            case '<':   return $a < $b;
            case '>':   return $a > $b;
            case '<=':  return $a <= $b;
            case '>=':  return $a >= $b;
        }
        return false;
    }

    /* ---------- Recursion guard ---------- */

    private function push_depth(): void {
        $this->depth++;
        if ( $this->depth > self::MAX_DEPTH ) {
            throw new \RuntimeException( 'Expression nested too deeply' );
        }
    }

    private function pop_depth(): void {
        $this->depth--;
    }
}
