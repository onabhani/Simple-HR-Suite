<?php
namespace SFS\HR\Core\LaborLaw;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use SFS\HR\Core\Company_Profile;

/**
 * Labor_Law_Service
 *
 * Dispatcher / factory for country-specific Labor_Law_Strategy
 * implementations. Resolves the current company's country from
 * Company_Profile and returns the matching strategy.
 *
 * Callers should always go through current() rather than instantiating
 * strategies directly, so that overrides and filters apply uniformly.
 *
 * @since M8
 */
class Labor_Law_Service {

    /** Registered strategy classes keyed by ISO country code. */
    private static array $registry = [
        'SA' => Saudi_Labor_Law::class,
        'AE' => UAE_Labor_Law::class,
        'BH' => Bahrain_Labor_Law::class,
        'KW' => Kuwait_Labor_Law::class,
        'OM' => Oman_Labor_Law::class,
        'QA' => Qatar_Labor_Law::class,
    ];

    /** Cached instances. */
    private static array $cache = [];

    /**
     * Get strategy for an explicit country code.
     *
     * Unknown codes fall back to Saudi_Labor_Law (the baseline implementation)
     * to preserve backward compatibility for any jurisdiction not yet modeled.
     */
    public static function for_country( string $country_code ): Labor_Law_Strategy {
        $code = strtoupper( trim( $country_code ) );

        if ( isset( self::$cache[ $code ] ) ) {
            return self::$cache[ $code ];
        }

        /**
         * Allow third parties to register custom strategies.
         *
         * @param array  $registry Map of country_code => FQCN implementing Labor_Law_Strategy.
         * @param string $code     The code being resolved.
         */
        $registry = apply_filters( 'sfs_hr_labor_law_registry', self::$registry, $code );

        $class = $registry[ $code ] ?? Saudi_Labor_Law::class;

        if ( ! class_exists( $class ) || ! is_subclass_of( $class, Labor_Law_Strategy::class ) ) {
            $class = Saudi_Labor_Law::class;
        }

        return self::$cache[ $code ] = new $class();
    }

    /**
     * Get strategy for the current company profile country.
     */
    public static function current(): Labor_Law_Strategy {
        $profile = Company_Profile::get();
        $code    = is_array( $profile ) && ! empty( $profile['country'] ) ? (string) $profile['country'] : 'SA';
        return self::for_country( $code );
    }

    /**
     * Supported country codes (those with a concrete strategy).
     */
    public static function supported_countries(): array {
        return array_keys( apply_filters( 'sfs_hr_labor_law_registry', self::$registry, '' ) );
    }

    /**
     * Return strategy details keyed by country — for admin UI display.
     *
     * @return array<string, array{code:string, name:string, formula:string}>
     */
    public static function describe_all(): array {
        $out = [];
        foreach ( self::supported_countries() as $code ) {
            $s = self::for_country( $code );
            $out[ $code ] = [
                'code'    => $s->country_code(),
                'name'    => $s->country_name(),
                'formula' => $s->gratuity_formula_description(),
            ];
        }
        return $out;
    }

    /** Clear the internal cache — used by tests or after profile changes. */
    public static function flush_cache(): void {
        self::$cache = [];
    }
}
