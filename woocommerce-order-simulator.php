<?php
 /**
  * Plugin Name: Order Simulator for WooCommerce
  * Plugin URI: http://www.75nineteen.com
  * Description: Automate orders to generate WooCommerce storefronts at scale for testing purposes.
  * Version: 1.2.2
  * Author: 75nineteen Media LLC
  * Author URI: http://www.75nineteen.com

  * Copyright 2015 75nineteen Media LLC.  (email : scott@75nineteen.com)
  *
  * This program is free software: you can redistribute it and/or modify
  * it under the terms of the GNU General Public License as published by
  * the Free Software Foundation, either version 3 of the License, or
  * (at your option) any later version.
  *
  * This program is distributed in the hope that it will be useful,
  * but WITHOUT ANY WARRANTY; without even the implied warranty of
  * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
  * GNU General Public License for more details.
  *
  * You should have received a copy of the GNU General Public License
  * along with this program.  If not, see <http://www.gnu.org/licenses/>.
  */

/**
 * DATA REALISM - what 1.2.0 changed and why.
 *
 * See order-simulator-data-realism.md (todos/pitch-midnight/ in the parker-context
 * repo) for the defect report this release answers. Two independent defects:
 *
 * 1. NO SURNAME DIVERSITY. Commit 708d383 (2020-02-06, "Updated fake names to be
 *    all example ridden") scrubbed the fixture so nothing in it could be mistaken
 *    for reachable contact data - emails to @example.com, phones to 555. The sweep
 *    also overwrote the `surname` column with the literal string "Example",
 *    collapsing 4,308 distinct surnames to 1. That was collateral damage, not the
 *    point of the scrub: a surname is not contact data. 1.2.0 restores the surname
 *    column and leaves the email and phone scrub in place, which is the intent the
 *    2020 commit was actually reaching for.
 *
 * 2. ORDERS BUILT FROM A FAILED USER. create_user() never checked
 *    wp_insert_user()'s return. As the 10,000-row fixture saturated, inserts began
 *    failing on duplicate email - the uniqueness pre-check only looked at
 *    user_login - and returned a WP_Error. That WP_Error went straight into
 *    get_user_meta(), which answers '' for every key, so the order was written with
 *    every address field blank. On sandbox.theritesites.com that is 18,289 of
 *    54,484 orders (33.6%), billing and shipping alike. 1.2.0 checks the return,
 *    and never writes an order it cannot give a real address to.
 *
 * The saturation itself is fixed by decoupling: given name and surname are now
 * drawn independently rather than read off one fixture row, so the identity space
 * is 1,340 x 4,308 rather than 10,000, and the pool cannot run dry.
 *
 * HPOS. The sandbox runs HPOS authoritative. The old code wrote order fields with
 * update_post_meta(), which under HPOS addresses the legacy post rather than the
 * orders table. Order writes now go through the CRUD layer.
 *
 * Verify with `wp wcos verify`, which measures the acceptance criteria from the
 * defect report directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Order_Simulator {

    /**
     * Fixture table name.
     *
     * Deliberately unprefixed - it has been unprefixed since 2015 and renaming it
     * would orphan the seeded data on every existing install. The cost is that a
     * multisite network, or several sites sharing one database, share one fixture
     * table. That is harmless here (the table is a read-only corpus, regenerable
     * from fakenames.sql) but it is the reason this constant exists rather than the
     * name being repeated inline.
     */
    const FAKENAMES_TABLE = 'fakenames';

    /**
     * Bumping this reseeds the fixture table on the next load.
     */
    const FAKENAMES_VERSION = 2;

    /** Mutex for the reseed. @see acquire_seed_lock() */
    const SEED_LOCK_OPTION = 'wcos_seed_lock';

    /**
     * Exponent applied to each surname's fixture frequency before drawing.
     *
     * The fixture's own frequencies are close to flat: the most common surname is
     * 0.47% of rows, so every search term ends up about as selective as every
     * other one and an index is never asked a hard question. Raising the counts to
     * this power sharpens the curve toward the long tail real surname data has.
     *
     * The exponent trades the head against the tail, and both are acceptance
     * criteria, so it was swept rather than guessed. Measured over a 50,000-order
     * replay of this generator:
     *
     *   1.5   3,123 distinct surnames   top 0.97%   head too flat
     *   1.7   2,488 distinct surnames   top 1.31%   <- both criteria, with margin
     *   1.8   2,197 distinct surnames   top 1.39%   thinner margin on distinct
     *   1.9   1,900 distinct surnames   top 1.50%   fails the 2,000 floor
     *
     * 1.5 looks fine on paper - the theoretical head is 1.11% - but customer reuse
     * flattens the realised distribution, and it lands just under the 1% floor. The
     * lesson is that this constant has to be measured against a replay, not
     * computed off the corpus. Filterable via wcos_surname_frequency_exponent.
     */
    const SURNAME_FREQUENCY_EXPONENT = 1.7;

    /**
     * Email domains, with the weight each carries out of 100.
     *
     * Every one is unregistrable: RFC 2606 reserves example.com/.net/.org and the
     * .example, .test and .invalid TLDs, and RFC 6761 reiterates .test and
     * .invalid. Mail addressed here cannot leave the building no matter what the
     * store's mail settings say.
     *
     * Weighted rather than uniform because real stores cluster hard on a handful
     * of providers. A flat spread across twelve domains would make every domain
     * equally selective, which is the same mistake the flat surname distribution
     * made - it just moves it to a different column.
     *
     * Deliberately NOT etest.com or sample.com: both are ordinary registrable .com
     * names owned by someone who is not us, so a store with mail enabled would aim
     * real messages at a real domain.
     */
    private static function email_domains() {
        return array(
            'example.com'     => 35,
            'example.net'     => 14,
            'example.org'     => 11,
            'mail.example'    => 8,
            'inbox.test'      => 7,
            'webmail.example' => 6,
            'mailbox.test'    => 5,
            'post.example'    => 4,
            'mail.invalid'    => 4,
            'users.invalid'   => 3,
            'relay.test'      => 2,
            'host.example'    => 1,
        );
    }

    private $users        = array();
    private $users_loaded = false;
    public $settings      = array();

    /** Lazily built name corpora. @see load_name_corpora() */
    private $given_pool    = null;
    private $surname_names = null;
    private $surname_cdf   = null;
    private $surname_total = 0.0;

    public function __construct() {
        register_activation_hook( __FILE__, array($this, 'install') );

        add_filter( 'cron_schedules', array($this, 'add_cron_schedule') );

        add_filter( 'woocommerce_get_settings_pages', array($this, 'settings_page') );

        add_action( 'wcos_create_orders', array($this, 'create_orders_on_init') );
        $this->settings = self::get_settings();

        // Existing installs carry a stale fixture. Activation already happened for
        // them, so the reseed cannot hang off register_activation_hook alone.
        add_action( 'plugins_loaded', array($this, 'maybe_reseed_fakenames') );

        add_action( 'woocommerce_order_status_completed', array( $this, 'trs_add_costs' ) );

        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            WP_CLI::add_command( 'wcos', 'WCOS_CLI_Command' );
        }
    }

    public function add_cron_schedule( $schedules ) {
        $schedules['ten_minutes'] = array(
            'interval' => 600,
            'display' => __('Ten Minutes')
        );
        return $schedules;
    }

    public function install() {
        global $wpdb;

        if ( ! wp_next_scheduled( 'wcos_create_orders' ) ) {
            wp_schedule_event( time(), 'hourly', 'wcos_create_orders' );
        }

        $wpdb->hide_errors();
        $collate = '';

        if ( method_exists($wpdb, 'has_cap') ) {
            if ( $wpdb->has_cap('collation') ) {
                if( ! empty($wpdb->charset ) ) $collate .= "DEFAULT CHARACTER SET $wpdb->charset";
                if( ! empty($wpdb->collate ) ) $collate .= " COLLATE $wpdb->collate";
            }
        } else {
            if ( $wpdb->supports_collation() ) {
                if( ! empty($wpdb->charset ) ) $collate .= "DEFAULT CHARACTER SET $wpdb->charset";
                if( ! empty($wpdb->collate ) ) $collate .= " COLLATE $wpdb->collate";
            }
        }

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $table = self::FAKENAMES_TABLE;

        $sql = "CREATE TABLE {$table} (
number int(11) NOT NULL AUTO_INCREMENT,
gender varchar(6) NOT NULL,
givenname varchar(20) NOT NULL,
surname varchar(23) NOT NULL,
streetaddress varchar(100) NOT NULL,
city varchar(100) NOT NULL,
state varchar(22) NOT NULL,
zipcode varchar(15) NOT NULL,
country varchar(2) NOT NULL,
countryfull varchar(100) NOT NULL,
emailaddress varchar(100) NOT NULL,
username varchar(25) NOT NULL,
password varchar(25) NOT NULL,
telephonenumber tinytext NOT NULL,
maidenname varchar(20) NOT NULL,
birthday varchar(10) NOT NULL,
company varchar(70) NOT NULL,
PRIMARY KEY  (number)
) $collate";
        dbDelta( $sql );

        $this->seed_fakenames();
    }

    /**
     * Reload the fixture when the shipped data is newer than what is in the table.
     *
     * Cheap in the common case: one autoloaded option read.
     */
    public function maybe_reseed_fakenames() {
        if ( (int) get_option( 'wcos_fakenames_version', 0 ) >= self::FAKENAMES_VERSION ) {
            return;
        }

        global $wpdb;
        $table = self::FAKENAMES_TABLE;

        // Nothing to reseed if the table was never created; activation handles that.
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
            return;
        }

        // Whichever request notices the stale version first does the work. Without
        // this, concurrent requests each truncate and reload 10,000 rows, and a
        // reader between one request's TRUNCATE and its INSERTs sees an empty
        // corpus.
        if ( ! $this->acquire_seed_lock() ) {
            return;
        }

        $this->seed_fakenames( true );

        $this->release_seed_lock();
    }

    /**
     * Claim the right to reseed, using an option row as the mutex.
     *
     * add_option() fails when the row already exists, and that failure is atomic at
     * the database level - which a get-then-set on a transient is not. A lock older
     * than five minutes is treated as abandoned, so a seed that fatals partway
     * through does not block the reseed forever.
     */
    private function acquire_seed_lock() {
        if ( add_option( self::SEED_LOCK_OPTION, time(), '', 'no' ) ) {
            return true;
        }

        $held_since = (int) get_option( self::SEED_LOCK_OPTION, 0 );

        if ( $held_since && ( time() - $held_since ) > 5 * MINUTE_IN_SECONDS ) {
            update_option( self::SEED_LOCK_OPTION, time(), 'no' );
            return true;
        }

        return false;
    }

    private function release_seed_lock() {
        delete_option( self::SEED_LOCK_OPTION );
    }

    /**
     * Load fakenames.sql into the fixture table.
     *
     * @param bool $force Truncate and reload even when the table already has rows.
     */
    public function seed_fakenames( $force = false ) {
        global $wpdb;

        $table = self::FAKENAMES_TABLE;
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

        if ( $count > 0 && ! $force ) {
            update_option( 'wcos_fakenames_version', self::FAKENAMES_VERSION );
            return $count;
        }

        $path = dirname( __FILE__ ) . '/fakenames.sql';
        $fh   = @fopen( $path, 'r' );

        if ( ! $fh ) {
            return new WP_Error( 'wcos_fixture_missing', sprintf( 'Cannot read %s', $path ) );
        }

        if ( $count > 0 ) {
            $wpdb->query( "TRUNCATE TABLE {$table}" );
        }

        $loaded = 0;
        $first  = true;

        // Streamed rather than file_get_contents()+explode(): the fixture is 2.3MB
        // and the old approach held the file and a 10,000-element array at once.
        while ( false !== ( $line = fgets( $fh ) ) ) {
            if ( $first ) {
                // The file used to carry a UTF-8 BOM, which glued itself to the
                // first INSERT and made that one statement fail silently - which is
                // why a 10,000-row fixture only ever had 9,999 rows in it.
                $line  = preg_replace( '/^\xEF\xBB\xBF/', '', $line );
                $first = false;
            }

            $line = trim( $line );

            if ( '' === $line || 0 !== stripos( $line, 'insert into ' . $table ) ) {
                continue;
            }

            if ( false !== $wpdb->query( $line ) ) {
                $loaded++;
            }
        }

        fclose( $fh );

        update_option( 'wcos_fakenames_version', self::FAKENAMES_VERSION );

        // A corpus loaded in a previous request is stale now.
        $this->given_pool = null;

        return $loaded;
    }

    public function settings_page( $settings ) {
        $settings[] = include( 'class-wc-settings-order-simulator.php' );

        return $settings;
    }

    public function create_orders_on_init() {
        //add_action( 'init', array($this, 'create_orders') );
        $this->create_orders();
    }

    public static function get_settings() {
        $settings = get_option( 'wc_order_simulator_settings', array() );
        $defaults = array(
            'orders_per_hour'       => 200,
            'products'              => array(),
            'min_order_products'    => 1,
            'max_order_products'    => 5,
            'create_users'          => true,
            'payment_method'        => 'auto',
            'shipping_method'       => 'auto',
            'order_completed_pct'   => 90,
            'order_processing_pct'  => 5,
            'order_failed_pct'      => 5
        );
        $settings = wp_parse_args( $settings, $defaults );

        return $settings;
    }

    /* ---------------------------------------------------------------------
     * Identity generation
     * ------------------------------------------------------------------ */

    /**
     * Build the given-name and surname corpora from the fixture table.
     *
     * Given names are held as a flat pool with repeats, so drawing uniformly from
     * it reproduces the fixture's own given-name frequencies for free. Surnames get
     * a cumulative distribution instead, because their weights are reshaped by
     * SURNAME_FREQUENCY_EXPONENT before drawing.
     *
     * Drawing the two independently is the point: reading both off one fixture row
     * caps the store at 10,000 identities and, worse, ties the surname you get to
     * the address you get. Independent draws give 1,340 x 4,308 combinations.
     */
    private function load_name_corpora() {
        if ( null !== $this->given_pool ) {
            return;
        }

        global $wpdb;
        $table = self::FAKENAMES_TABLE;

        $this->given_pool = $wpdb->get_col(
            "SELECT givenname FROM {$table} WHERE givenname <> ''"
        );

        $rows = $wpdb->get_results(
            "SELECT surname, COUNT(*) AS c FROM {$table} WHERE surname <> '' GROUP BY surname"
        );

        $exponent = (float) apply_filters(
            'wcos_surname_frequency_exponent',
            self::SURNAME_FREQUENCY_EXPONENT
        );

        $names = array();
        $cdf   = array();
        $acc   = 0.0;

        foreach ( (array) $rows as $row ) {
            $acc    += pow( (float) $row->c, $exponent );
            $names[] = $row->surname;
            $cdf[]   = $acc;
        }

        $this->surname_names = $names;
        $this->surname_cdf   = $cdf;
        $this->surname_total = $acc;
    }

    /**
     * Draw a surname, weighted so the distribution has a head and a long tail.
     */
    private function pick_surname() {
        if ( empty( $this->surname_cdf ) ) {
            return '';
        }

        $target = ( mt_rand() / mt_getrandmax() ) * $this->surname_total;

        $lo = 0;
        $hi = count( $this->surname_cdf ) - 1;

        while ( $lo < $hi ) {
            $mid = intdiv( $lo + $hi, 2 );
            if ( $this->surname_cdf[ $mid ] < $target ) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }

        return $this->surname_names[ $lo ];
    }

    /**
     * Reduce a name to something usable in a login and an email local part.
     */
    private function ascii_slug( $value ) {
        $value = remove_accents( (string) $value );
        return strtolower( preg_replace( '/[^A-Za-z0-9]/', '', $value ) );
    }

    /**
     * Draw an email domain against its weight.
     */
    private function pick_email_domain() {
        $domains = self::email_domains();
        $roll    = mt_rand( 1, array_sum( $domains ) );
        $acc     = 0;

        foreach ( $domains as $domain => $weight ) {
            $acc += $weight;
            if ( $roll <= $acc ) {
                return $domain;
            }
        }

        return 'example.com';
    }

    /**
     * Compose an email local part from a name.
     *
     * Every pattern keeps the surname whole, which is what lets a search by name
     * and a search by email agree. Patterns vary because a store where every
     * address is first.last is a store where one substring rule matches
     * everything - a lookup feature can get its parsing wrong and still look
     * right.
     *
     * @param bool $numbered Append digits. Reserved for retries, so that early
     *                       customers get the clean address and later ones get the
     *                       numbered variant, the way real mailboxes fill up.
     */
    private function email_local_part( $given, $surname, $numbered = false ) {
        $initial = substr( $given, 0, 1 );

        $patterns = array(
            $given . '.' . $surname,
            $given . $surname,
            $initial . $surname,
            $given . '_' . $surname,
            $surname . '.' . $given,
            $given . '-' . $surname,
            $initial . '.' . $surname,
        );

        $base = $patterns[ mt_rand( 0, count( $patterns ) - 1 ) ];

        if ( ! $numbered ) {
            return $base;
        }

        return mt_rand( 1, 100 ) <= 40
            ? $base . mt_rand( 1940, 2009 )   // reads as a birth year
            : $base . mt_rand( 2, 9999 );
    }

    /**
     * Generate a telephone number for a country, in that country's own format.
     *
     * OFFICIALLY RESERVED. These ranges are set aside by the national numbering
     * authority for fiction and can never be allocated to a subscriber:
     *
     *   US, CA  NANP 555-0100..555-0199
     *   GB      Ofcom drama ranges (01632 960xxx, 020 7946 0xxx, 07700 900xxx)
     *   AU      ACMA drama ranges ((0x) 5550 1xxx, 0491 570 xxx)
     *   FR      ARCEP fiction ranges (01 99 00 xx xx and the per-zone equivalents)
     *
     * CONVENTION ONLY. NZ, BE and BR publish no fictional range this plugin can
     * cite. Those follow the national format with a 555/5550 subscriber block,
     * which is the usual fake-number convention but is not a guarantee the number
     * is unallocated. Nothing here dials anything, so the exposure is a human
     * copying a number out of test data - small, but real, and better written down
     * than discovered.
     *
     * Generated per order rather than read off the fixture row, so phone variety
     * is not capped at the fixture's 10,000 values.
     */
    private function random_phone( $country ) {
        $pick = function ( array $set ) {
            return $set[ mt_rand( 0, count( $set ) - 1 ) ];
        };

        $digits = function ( $n ) {
            return str_pad( (string) mt_rand( 0, pow( 10, $n ) - 1 ), $n, '0', STR_PAD_LEFT );
        };

        switch ( strtoupper( (string) $country ) ) {
            case 'US':
                return sprintf( '(%d) 555-01%s', $pick( self::us_area_codes() ), $digits( 2 ) );

            case 'CA':
                return sprintf( '(%d) 555-01%s', $pick( self::ca_area_codes() ), $digits( 2 ) );

            case 'GB':
                return mt_rand( 1, 100 ) <= 30
                    ? sprintf( '07700 900%s', $digits( 3 ) )
                    : sprintf( '%s%s', $pick( self::gb_drama_prefixes() ), $digits( 3 ) );

            case 'AU':
                return mt_rand( 1, 100 ) <= 30
                    ? sprintf( '0491 570 %s', $digits( 3 ) )
                    : sprintf( '(0%d) 5550 1%s', $pick( array( 2, 3, 7, 8 ) ), $digits( 3 ) );

            case 'FR':
                return sprintf(
                    '%s %s %s',
                    $pick( array( '01 99 00', '02 61 91', '03 53 01', '04 65 71', '05 36 49', '06 39 98' ) ),
                    $digits( 2 ),
                    $digits( 2 )
                );

            case 'NZ':   // convention
                return mt_rand( 1, 100 ) <= 30
                    ? sprintf( '021 555 0%s', $digits( 3 ) )
                    : sprintf( '(0%d) 555 0%s', $pick( array( 3, 4, 6, 7, 9 ) ), $digits( 3 ) );

            case 'BE':   // convention
                $roll = mt_rand( 1, 100 );
                if ( $roll <= 30 ) {
                    return sprintf( '04%d 55 01 %s', $pick( array( 70,71,72,73,74,75,76,77,78,79,83,84,85,86,87,88,89 ) ), $digits( 2 ) );
                }
                if ( $roll <= 55 ) {
                    return sprintf( '0%d 555 01 %s', $pick( array( 2, 3, 4, 9 ) ), $digits( 2 ) );
                }
                // Belgium mixes two- and three-digit area codes. Both are used
                // because the four two-digit codes alone only span 400 numbers.
                return sprintf( '0%d 55 01 %s', $pick( array( 10,11,12,13,14,15,16,19,50,51,52,53,54,55,56,57,58,59,60,61,63,64,65,67,68,69,71,80,81,82,83,84,85,86,87,89 ) ), $digits( 2 ) );

            case 'BR':   // convention
                return mt_rand( 1, 100 ) <= 30
                    ? sprintf( '(%d) 95550-1%s', $pick( self::br_area_codes() ), $digits( 3 ) )
                    : sprintf( '(%d) 5550-1%s', $pick( self::br_area_codes() ), $digits( 3 ) );
        }

        return sprintf( '(%d) 555-01%s', $pick( self::us_area_codes() ), $digits( 2 ) );
    }

    private static function us_area_codes() {
        return array(201,202,203,205,206,207,208,209,210,212,213,214,215,216,217,218,219,224,225,228,229,231,234,239,240,248,251,252,253,254,256,260,262,267,269,270,276,281,301,302,303,304,305,307,308,309,310,312,313,314,315,316,317,318,319,320,321,323,325,330,331,334,336,337,339,346,347,351,352,360,361,364,380,385,386,401,402,404,405,406,407,408,409,410,412,413,414,415,417,419,423,424,425,430,432,434,435,440,442,443,458,463,469,470,475,478,479,480,484,501,502,503,504,505,507,508,509,510,512,513,515,516,517,518,520,530,531,534,539,540,541,551,559,561,562,563,564,567,570,571,573,574,575,580,585,586,601,602,603,605,606,607,608,609,610,612,614,615,616,617,618,619,620,623,626,628,629,630,631,636,641,646,650,651,657,660,661,662,667,669,678,681,682,701,702,703,704,706,707,708,712,713,714,715,716,717,718,719,720,724,725,727,731,732,734,737,740,743,747,754,757,760,762,763,765,769,770,772,773,774,775,779,781,785,786,801,802,803,804,805,806,808,810,812,813,814,815,816,817,818,828,830,831,832,843,845,847,848,850,854,856,857,858,859,860,862,863,864,865,870,872,878,901,903,904,906,907,908,909,910,912,913,914,915,916,917,918,919,920,925,928,929,930,931,934,936,937,938,940,941,947,949,951,952,954,956,959,970,971,972,973,978,979,980,984,985,989);
    }

    private static function ca_area_codes() {
        return array(204,226,236,249,250,289,306,343,365,387,403,416,418,431,437,438,450,506,514,519,548,579,581,587,604,613,639,647,672,705,709,742,778,780,782,807,819,825,867,873,902,905);
    }

    private static function br_area_codes() {
        return array(11,12,13,14,15,16,17,18,19,21,22,24,27,28,31,32,33,34,35,37,38,41,42,43,44,45,46,47,48,49,51,53,54,55,61,62,63,64,65,66,67,68,69,71,73,74,75,77,79,81,82,83,84,85,86,87,88,89,91,92,93,94,95,96,97,98,99);
    }

    private static function gb_drama_prefixes() {
        return array(
            '01632 960', '020 7946 0', '0113 496 0', '0114 496 0', '0115 496 0',
            '0116 496 0', '0117 496 0', '0118 496 0', '0121 496 0', '0131 496 0',
            '0141 496 0', '0151 496 0', '0161 496 0', '0191 498 0', '028 9018 0',
            '029 2018 0',
        );
    }

    /**
     * Compose an unused synthetic identity.
     *
     * The email is derived from the name rather than drawn separately, so that
     * searching a customer by surname and searching them by email select the same
     * orders. When those two generators are unrelated - as they were before 1.2.0 -
     * a search feature can get one of them wrong and still look correct.
     *
     * @return array|WP_Error given, surname, login, email
     */
    private function generate_identity() {
        $this->load_name_corpora();

        if ( empty( $this->given_pool ) || empty( $this->surname_names ) ) {
            return new WP_Error(
                'wcos_empty_corpus',
                'The fakenames fixture is empty. Reactivate Order Simulator, or run `wp wcos seed --force`, to load it.'
            );
        }

        $given_count = count( $this->given_pool );

        for ( $attempt = 0; $attempt < 10; $attempt++ ) {
            $given   = $this->given_pool[ mt_rand( 0, $given_count - 1 ) ];
            $surname = $this->pick_surname();

            $slug_given   = $this->ascii_slug( $given );
            $slug_surname = $this->ascii_slug( $surname );

            if ( '' === $slug_given || '' === $slug_surname ) {
                continue;
            }

            // First couple of attempts go for a clean address; only once those
            // collide does the number appear. Early customers end up with
            // j.moreno@ and later ones with j.moreno4471@, which is how real
            // mailboxes fill up.
            $local = $this->email_local_part( $slug_given, $slug_surname, $attempt >= 2 );
            $login = $local;
            $email = $local . '@' . $this->pick_email_domain();

            // Both checks, not just the login one. Checking only user_login is the
            // bug that made wp_insert_user() fail on duplicate email once the
            // fixture saturated, which is where the blank orders came from.
            if ( username_exists( $login ) || email_exists( $email ) ) {
                continue;
            }

            return array(
                'given'   => $given,
                'surname' => $surname,
                'login'   => $login,
                'email'   => $email,
            );
        }

        return new WP_Error( 'wcos_identity_collision', 'Could not compose an unused identity in 10 attempts.' );
    }

    /**
     * Draw a random street address from the fixture.
     *
     * Uses the primary key rather than ORDER BY RAND(), which sorts the whole table
     * on every call.
     */
    private function random_address_row() {
        global $wpdb;
        $table = self::FAKENAMES_TABLE;

        $max = (int) $wpdb->get_var( "SELECT MAX(number) FROM {$table}" );

        if ( $max < 1 ) {
            return null;
        }

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE number >= %d ORDER BY number LIMIT 1",
            mt_rand( 1, $max )
        ) );
    }

    /**
     * Write billing and shipping meta for a user from an identity and an address.
     */
    private function write_address_meta( $user_id, $identity, $address ) {
        $fields = array(
            'first_name' => $identity['given'],
            'last_name'  => $identity['surname'],
            'address_1'  => $address->streetaddress,
            'city'       => $address->city,
            'state'      => $address->state,
            'postcode'   => $address->zipcode,
            'country'    => $address->country,
            'email'      => $identity['email'],
            // Generated from the country rather than copied off the fixture row,
            // so phone variety is not capped at the fixture's 10,000 values.
            'phone'      => $this->random_phone( $address->country ),
        );

        foreach ( array( 'billing', 'shipping' ) as $type ) {
            foreach ( $fields as $key => $value ) {
                update_user_meta( $user_id, $type . '_' . $key, $value );
            }
        }
    }

    /**
     * Create a customer account with a synthetic identity.
     *
     * @return int|WP_Error
     */
    public function create_user() {
        $identity = $this->generate_identity();

        if ( is_wp_error( $identity ) ) {
            return $identity;
        }

        $address = $this->random_address_row();

        if ( ! $address ) {
            return new WP_Error( 'wcos_no_address', 'The fakenames fixture has no address rows.' );
        }

        $user_id = wp_insert_user( array(
            'user_login' => $identity['login'],
            'user_pass'  => wp_generate_password( 24 ),
            'user_email' => $identity['email'],
            'first_name' => $identity['given'],
            'last_name'  => $identity['surname'],
            'role'       => 'customer',
        ) );

        // The check that was missing. Without it a WP_Error flows into
        // get_user_meta(), which answers '' for every key, and the order is written
        // with a completely blank address.
        if ( is_wp_error( $user_id ) ) {
            return $user_id;
        }

        $this->write_address_meta( $user_id, $identity, $address );

        // Keep the reuse pool current so new accounts can be drawn again this run.
        if ( $this->users_loaded ) {
            $this->users[] = $user_id;
        }

        return $user_id;
    }

    /**
     * Pick an existing customer, or 0 when there are none.
     *
     * Returned 0 or null out of an empty pool before 1.2.0 - rand(0, -1) on an
     * empty array - which produced the same blank-address order as a failed insert.
     */
    public function get_random_user() {
        // Tracked with a flag rather than by emptiness: on a store with no
        // customers yet, testing the array itself re-runs the query every call and
        // never lets newly created accounts into the pool.
        if ( ! $this->users_loaded ) {
            $this->users        = get_users( array( 'role' => 'Customer', 'fields' => 'ID' ) );
            $this->users_loaded = true;
        }

        $length = count( $this->users );

        if ( $length < 1 ) {
            return 0;
        }

        return (int) $this->users[ mt_rand( 0, $length - 1 ) ];
    }

    /**
     * Repair a customer whose address meta is empty.
     *
     * Narrow on purpose: it fires only when billing_last_name is blank, and it
     * leaves the account's own email alone so the login keeps working. Customers
     * that merely carry a stale name are left as they are - use
     * `wp wcos refresh-customers` to re-identify those deliberately.
     *
     * @return bool Whether the user now has usable address meta.
     */
    private function ensure_address_meta( $user_id ) {
        if ( '' !== trim( (string) get_user_meta( $user_id, 'billing_last_name', true ) ) ) {
            return true;
        }

        $identity = $this->generate_identity();

        if ( is_wp_error( $identity ) ) {
            return false;
        }

        $address = $this->random_address_row();

        if ( ! $address ) {
            return false;
        }

        $user = get_userdata( $user_id );

        if ( $user && $user->user_email ) {
            $identity['email'] = $user->user_email;
        }

        $this->write_address_meta( $user_id, $identity, $address );

        return true;
    }

    /**
     * Resolve a usable customer for one order.
     *
     * @return int Customer ID, or 0 when no usable customer could be produced.
     */
    private function resolve_customer() {
        if ( ! $this->settings['create_users'] ) {
            // "No - assign existing accounts to new orders". Creating one anyway
            // would quietly overrule the setting.
            $candidates = array( 'get_random_user' );
        } elseif ( mt_rand( 1, 100 ) <= 50 ) {
            $candidates = array( 'create_user', 'get_random_user' );
        } else {
            $candidates = array( 'get_random_user', 'create_user' );
        }

        foreach ( $candidates as $method ) {
            $user_id = $this->$method();

            if ( is_wp_error( $user_id ) || ! $user_id ) {
                continue;
            }

            if ( $this->ensure_address_meta( $user_id ) ) {
                return (int) $user_id;
            }
        }

        return 0;
    }

    /* ---------------------------------------------------------------------
     * Order generation
     * ------------------------------------------------------------------ */

    public function create_orders() {
        global $wpdb, $woocommerce;

        if ( empty( $this->settings['orders_per_hour'] ) ) {
            return;
        }

        set_time_limit(0);

        $woocommerce->init();
        $woocommerce->frontend_includes();

        $session_class = apply_filters( 'woocommerce_session_handler', 'WC_Session_Handler' );

        // Class instances
        require_once WC()->plugin_path() .'/includes/abstracts/abstract-wc-session.php';
        $woocommerce->session  = new WC_Session_Handler();
        $woocommerce->cart     = new WC_Cart();                                    // Cart class, stores the cart contents
        $woocommerce->customer = new WC_Customer();                                // Customer class, handles data such as customer location

        $woocommerce->countries = new WC_Countries();
        $woocommerce->checkout = new WC_Checkout();
        //$woocommerce->product_factory = new WC_Product_Factory();                      // Product Factory to create new product instances
        $woocommerce->order_factory   = new WC_Order_Factory();                        // Order Factory to create new order instances
        $woocommerce->integrations    = new WC_Integrations();                         // Integrations class


        // clear cart
        if (! defined('WOOCOMMERCE_CHECKOUT')) define('WOOCOMMERCE_CHECKOUT', true);
        $woocommerce->cart->empty_cart();

        $product_ids = $this->settings['products'];

        if ( empty( $product_ids ) ) {
            $products = $wpdb->get_col("SELECT ID FROM {$wpdb->prefix}posts WHERE post_type = 'product'");

            foreach ( $products as $product_id ) {
                $product_ids[] = $product_id;
            }
        }

        if ( empty( $product_ids ) ) {
            return;
        }

        $skipped = 0;

        for ( $x = 0; $x < $this->settings['orders_per_hour']; $x++ ) {
            $num_products = rand( $this->settings['min_order_products'], $this->settings['max_order_products'] );

            $user_id = $this->resolve_customer();

            // Skip rather than write an order nobody can be found by. A blank
            // address passes any test that only asks "did the search return
            // something", which is how 18,289 unusable orders got onto the sandbox
            // without anyone noticing.
            if ( ! $user_id ) {
                $skipped++;
                continue;
            }

            $data = $this->checkout_data_for_user( $user_id );

            if ( ! $data ) {
                $skipped++;
                continue;
            }

            // add random products to cart
            for ( $i = 0; $i < $num_products; $i++ ) {
                $idx = rand(0, count($product_ids)-1);
                $product_id = $product_ids[$idx];
                $woocommerce->cart->add_to_cart( $product_id, 1 );
            }

            $checkout = new WC_Checkout();

            $woocommerce->cart->calculate_totals();

            $order_id = $checkout->create_order( $data );

            if ( $order_id && ! is_wp_error( $order_id ) ) {
                $order = wc_get_order( $order_id );
            } else {
                $order = false;
            }

            if ( $order ) {
                // CRUD rather than update_post_meta(). Under HPOS the orders table
                // is authoritative and post meta is at best a synced copy, so the
                // old update_post_meta() calls wrote to the wrong place - including
                // _customer_user, which is how an order is found by its customer.
                $order->set_address( $this->address_subset( $data, 'billing' ), 'billing' );
                $order->set_address( $this->address_subset( $data, 'shipping' ), 'shipping' );

                $order->set_customer_id( absint( $user_id ) );
                $order->set_payment_method( 'bacs' );
                $order->set_payment_method_title( 'BACS' );

                $country_code = $order->get_shipping_country();

				// Set the array for tax calculations
				$calculate_tax_for = array(
					'country' => $country_code,
					'state' => '', // Can be set (optional)
					'postcode' => '', // Can be set (optional)
					'city' => '', // Can be set (optional)
				);

				// Optionally, set a total shipping amount
				$new_ship_price = floatval( mt_rand( 300, 1000 ) / 100 );

				// Get a new instance of the WC_Order_Item_Shipping Object
				$item = new WC_Order_Item_Shipping();

				$item->set_method_title( "Flat rate" );
				$item->set_method_id( "flat_rate:4" );
				$item->set_total( $new_ship_price ); // (optional)
				$item->calculate_taxes($calculate_tax_for);

				$order->add_item( $item );

				$order->calculate_totals();
				$order->save();

                do_action( 'woocommerce_checkout_order_processed', $order_id, $data, $order );

                // figure out the order status
                $status = 'completed';
                $rand = mt_rand(1, 100);
                $completed_pct  = $this->settings['order_completed_pct']; // e.g. 90
                $processing_pct = $completed_pct + $this->settings['order_processing_pct']; // e.g. 90 + 5
                $failed_pct     = $processing_pct + $this->settings['order_failed_pct']; // e.g. 95 + 5

                if ( $this->settings['order_completed_pct'] > 0 && $rand <= $completed_pct ) {
                    $status = 'completed';
                } elseif ( $this->settings['order_processing_pct'] > 0 && $rand <= $processing_pct ) {
                    $status = 'processing';
                } elseif ( $this->settings['order_failed_pct'] > 0 && $rand <= $failed_pct ) {
                    $status = 'failed';
                }

                if ( $status == 'failed' ) {
                    $order->update_status( $status );
                } else {
                    $order->payment_complete();
                    $order->update_status( $status );
                }
            }

            // clear cart
            $woocommerce->cart->empty_cart();
        }

        if ( $skipped ) {
            error_log( sprintf(
                '[order-simulator] skipped %d of %d orders: no customer with a usable address.',
                $skipped,
                $this->settings['orders_per_hour']
            ) );
        }
    }

    /**
     * Build checkout data from a customer, or false when a field is missing.
     *
     * The emptiness check is the guard rail. Every field here is one a lookup
     * feature might be asked to search on, so an order missing any of them is a
     * fixture that cannot fail the way production fails.
     *
     * @return array|false
     */
    private function checkout_data_for_user( $user_id ) {
        $keys = array(
            'country', 'first_name', 'last_name', 'company', 'address_1',
            'address_2', 'city', 'state', 'postcode', 'email', 'phone',
        );

        $data = array();

        foreach ( array( 'billing', 'shipping' ) as $type ) {
            foreach ( $keys as $key ) {
                $meta_key = $type . '_' . $key;

                $data[ $meta_key ] = in_array( $key, array( 'company', 'address_2' ), true )
                    ? ''
                    : (string) get_user_meta( $user_id, $meta_key, true );
            }
        }

        $required = array(
            'first_name', 'last_name', 'address_1', 'city', 'postcode', 'country', 'email',
        );

        foreach ( array( 'billing', 'shipping' ) as $type ) {
            foreach ( $required as $key ) {
                if ( '' === trim( $data[ $type . '_' . $key ] ) ) {
                    return false;
                }
            }
        }

        return $data;
    }

    /**
     * Strip the billing_/shipping_ prefix for WC_Order::set_address().
     */
    private function address_subset( $data, $type ) {
        $out    = array();
        $prefix = $type . '_';

        foreach ( $data as $key => $value ) {
            if ( 0 === strpos( $key, $prefix ) ) {
                $out[ substr( $key, strlen( $prefix ) ) ] = $value;
            }
        }

        return $out;
    }

    public function trs_add_costs( $order_id ) {

        $default_cost_categories = array();
        $costs_meta_extensions = apply_filters( 'trs_wc_np_order_cost_extension', array() );

		$costs_meta_fields 	 = apply_filters( 'trs_wc_np_order_cost_meta_fields', array_values( wp_list_pluck( $costs_meta_extensions, 'key' ) ) );

        if ( ! empty( $costs_meta_fields ) ) {
			foreach ( $costs_meta_fields as $key => $cost_meta_key ) {

				$current_key = trim( $cost_meta_key );

				if ( isset( $costs_meta_extensions[$current_key]->category ) ) {
					$default_cost_categories[$costs_meta_extensions[$current_key]->category] = $costs_meta_extensions[$current_key]->key;
				}
			}
		}

        if ( empty( $default_cost_categories ) ) {
            return;
        }

        $order = wc_get_order( $order_id );

        // wc_get_order() returns false for a deleted or invalid order, and the old
        // code called ->save() on it unconditionally at the end of every iteration.
        if ( ! $order ) {
            return;
        }

        $total = (float) $order->get_total();

        foreach( $default_cost_categories as $cost_category => $cost_key ) {
            if ( $cost_category === 'cost_of_shipping' ) {
                $first = mt_rand( 2, 6 );
                $second = mt_rand( 0, 99 ) / 100;
                $order->update_meta_data( $cost_key, ( $total / $first ) + $second );
                if ( $cost_key === '_wc_cost_of_shipping' ) {
                    $method = mt_rand( 1, 3 );
                    $third = 'manual';
                    switch ($method ) {
                        case 1:
                            $third = 'wc-services';
                        break;
                        case 2:
                            $third = 'shipstation';
                        break;
                        case 3:
                        default:
                            $third = 'manual';
                        break;
                    }
                    $order->update_meta_data( '_wc_cos_method', $third );
                }
            }
            if ( $cost_category === 'additional_costs' ) {
                $loop = mt_rand( 1,5 );
                $val = array();
                for ( $i = 0; $i < $loop; $i++ ) {

                    $first = mt_rand( 1, 5 );
                    $second = mt_rand( 0, 99 ) / 100;
                    array_push( $val, (object)array( 'label' => 'arg ' . $first, 'cost' => floatval( $first + $second ) ) );
                }
                $order->update_meta_data( $cost_key, json_encode($val) );
            }
            if ( $cost_category === 'cost_of_goods' ) {
                // get_post_meta() reads the legacy post, which under HPOS is not
                // where this order's meta lives.
                $stored_cog = $order->get_meta( $cost_key, true );

                if ( empty( $stored_cog ) || floatval( $stored_cog ) <= 0 ) {
                    $first = mt_rand( 3, 7 );
                    $second = mt_rand( 0, 3 );
                    $third = floatval( $total / $first ) + ( 0.25 * $second );
                    $order->update_meta_data( $cost_key, floatval( $third ) );
                }
            }
        }

        $order->save();
    }

    public function trs_add_cost_of_shipping( $order_id ) {

        $order = wc_get_order( $order_id );
        if( ! empty( $order ) ) {
            $first = mt_rand( 2, 6 );
            $second = mt_rand( 0, 99 ) / 100;
            $method = mt_rand( 1, 3 );
            $third = 'manual';
            switch ($method ) {
                case 1:
                    $third = 'wc-services';
                break;
                case 2:
                    $third = 'shipstation';
                break;
                case 3:
                default:
                    $third = 'manual';
                break;
            }
            $total = (float)$order->get_total();
            $order->update_meta_data( '_wc_cost_of_shipping', ( $total / $first ) + $second );
            $order->update_meta_data( '_wc_cos_method', $third );
            $order->save();
        }
    }

    /**
     * Re-identify existing customers whose name meta is stale.
     *
     * Backs `wp wcos refresh-customers`. Deliberately not automatic: it rewrites
     * account and address names on real user rows, and that is a decision to make
     * on purpose rather than a side effect of a cron tick.
     *
     * @param string   $match_last_name Only touch customers with this billing surname.
     * @param int      $limit           Maximum customers to touch.
     * @param callable $progress        Called with each user ID processed.
     *
     * @return array updated, skipped
     */
    public function refresh_customer_identities( $match_last_name = '', $limit = 0, $progress = null ) {
        $args = array( 'role' => 'Customer', 'fields' => 'ID' );

        if ( '' !== $match_last_name ) {
            $args['meta_key']   = 'billing_last_name';
            $args['meta_value'] = $match_last_name;
        }

        if ( $limit > 0 ) {
            $args['number'] = $limit;
        }

        $user_ids = get_users( $args );

        $updated = 0;
        $skipped = 0;

        foreach ( $user_ids as $user_id ) {
            $identity = $this->generate_identity();
            $address  = $this->random_address_row();

            if ( is_wp_error( $identity ) || ! $address ) {
                $skipped++;
                continue;
            }

            // The account keeps its email so the login still resolves; the address
            // email is what a lookup feature searches, and that one is regenerated
            // from the new name.
            $this->write_address_meta( $user_id, $identity, $address );

            update_user_meta( $user_id, 'first_name', $identity['given'] );
            update_user_meta( $user_id, 'last_name', $identity['surname'] );

            $updated++;

            if ( is_callable( $progress ) ) {
                call_user_func( $progress, $user_id );
            }
        }

        return array( 'updated' => $updated, 'skipped' => $skipped );
    }

    /* ---------------------------------------------------------------------
     * Repairing orders that already exist
     * ------------------------------------------------------------------ */

    /**
     * Order IDs after a given ID, in ID order.
     *
     * Batching is keyed on the ID rather than an offset so that a run interrupted
     * halfway resumes where it stopped instead of re-walking from the start - the
     * sandbox has 54,484 orders and a hosting platform that will not let one
     * process hold the CPU for as long as a single pass over them takes.
     */
    private function order_ids_after( $after_id, $limit ) {
        global $wpdb;

        $hpos = $wpdb->prefix . 'wc_orders';

        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$hpos}'" ) === $hpos ) {
            return $wpdb->get_col( $wpdb->prepare(
                "SELECT id FROM {$hpos} WHERE id > %d AND type = 'shop_order' ORDER BY id ASC LIMIT %d",
                $after_id,
                $limit
            ) );
        }

        return $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type = 'shop_order' ORDER BY ID ASC LIMIT %d",
            $after_id,
            $limit
        ) );
    }

    /**
     * Read a customer's stored identity into the shape WC_Order::set_address() wants.
     *
     * Reading it off the user rather than generating it here is what keeps the
     * operation idempotent and keeps a customer's orders agreeing with each other.
     * Generate per order instead and a customer with six orders ends up with six
     * different names, which destroys the repeat-customer relationship the store is
     * meant to have.
     *
     * @return array|false False when the customer has nothing usable to copy.
     */
    private function identity_from_user( $user_id ) {
        $address = array();

        foreach ( array( 'first_name', 'last_name', 'address_1', 'city', 'state', 'postcode', 'country', 'email', 'phone' ) as $key ) {
            $address[ $key ] = (string) get_user_meta( $user_id, 'billing_' . $key, true );
        }

        foreach ( array( 'first_name', 'last_name', 'address_1', 'city', 'postcode', 'country', 'email' ) as $key ) {
            if ( '' === trim( $address[ $key ] ) ) {
                return false;
            }
        }

        $address['company']   = '';
        $address['address_2'] = '';

        return $address;
    }

    /**
     * Compose a one-off identity for an order with no usable customer behind it.
     *
     * @return array|false
     */
    private function fresh_address_set() {
        $identity = $this->generate_identity();
        $row      = $this->random_address_row();

        if ( is_wp_error( $identity ) || ! $row ) {
            return false;
        }

        return array(
            'first_name' => $identity['given'],
            'last_name'  => $identity['surname'],
            'company'    => '',
            'address_1'  => $row->streetaddress,
            'address_2'  => '',
            'city'       => $row->city,
            'state'      => $row->state,
            'postcode'   => $row->zipcode,
            'country'    => $row->country,
            'email'      => $identity['email'],
            'phone'      => $this->random_phone( $row->country ),
        );
    }

    /**
     * Copy each order's customer identity onto the order itself.
     *
     * Written for the sandbox, where 54,484 orders are perfectly good apart from
     * their identity columns. Deleting and regenerating them costs hours and loses
     * the dates, totals, line items and status mix that are already right; this
     * rewrites the four columns that are wrong and leaves everything else alone.
     *
     * Goes through the CRUD layer rather than straight SQL. Direct UPDATEs would be
     * perhaps a hundred times faster, but the order data lives in the orders table,
     * a synced copy in post meta, and again in the analytics lookup tables - and
     * hand-maintaining that fan-out is precisely the class of mistake that produced
     * the defect this whole change is repairing. An hour of unattended CRUD is the
     * cheaper trade.
     *
     * Run `wp wcos refresh-customers` first: this copies what the customer record
     * currently says, so customers still carrying the old identity would have it
     * faithfully copied onto their orders.
     *
     * @param int  $limit    Orders to process this invocation.
     * @param int  $after_id Resume point; only orders with a greater ID are touched.
     * @param bool $dry_run  Report without writing.
     *
     * @return array Stats, including last_id for the next invocation.
     */
    public function reidentify_orders( $limit = 2000, $after_id = 0, $dry_run = false ) {
        $stats = array(
            'examined'     => 0,
            'updated'      => 0,
            'skipped'      => 0,
            'from_guest'   => 0,
            'filled_blank' => 0,
            'last_id'      => (int) $after_id,
            'customer_ids' => array(),
            'samples'      => array(),
        );

        foreach ( $this->order_ids_after( $after_id, $limit ) as $order_id ) {
            $stats['examined']++;
            $stats['last_id'] = (int) $order_id;

            $order = wc_get_order( $order_id );

            if ( ! $order ) {
                $stats['skipped']++;
                continue;
            }

            $customer_id = (int) $order->get_customer_id();

            // Recorded so a dry run answers "what is behind the blank orders?"
            // without anyone having to write the join by hand.
            $bucket = $customer_id > 1 ? 'customer' : ( 1 === $customer_id ? 'admin(1)' : 'guest(0)' );
            $stats['customer_ids'][ $bucket ] = ( $stats['customer_ids'][ $bucket ] ?? 0 ) + 1;

            $address = $customer_id ? $this->identity_from_user( $customer_id ) : false;

            if ( ! $address ) {
                $address = $this->fresh_address_set();
                $stats['from_guest']++;
            }

            if ( ! $address ) {
                $stats['skipped']++;
                continue;
            }

            $was_blank = '' === trim( (string) $order->get_billing_address_1() );

            if ( $was_blank ) {
                $stats['filled_blank']++;
            }

            if ( count( $stats['samples'] ) < 5 ) {
                $stats['samples'][] = sprintf(
                    '#%d  %s -> %s, %s, %s',
                    $order_id,
                    $order->get_billing_last_name() ? $order->get_billing_last_name() : '(blank)',
                    $address['last_name'],
                    $address['email'],
                    $address['phone']
                );
            }

            if ( $dry_run ) {
                continue;
            }

            $order->set_address( $address, 'billing' );
            $order->set_address( $address, 'shipping' );
            $order->save();

            $stats['updated']++;
        }

        return $stats;
    }

    /**
     * The settings-page field IDs that WooCommerce has been writing as options.
     *
     * These are form field names, not settings. Everything this plugin actually
     * reads lives in `wc_order_simulator_settings`. WooCommerce writes them anyway
     * because it treats a field `id` as an option name, and then renders the field
     * from the option rather than from the real setting - which is what made the
     * settings page appear to ignore edits.
     *
     * Listed explicitly rather than matched on the `wcos_` prefix, because the
     * plugin's own bookkeeping options share that prefix and a wildcard delete
     * would take `wcos_fakenames_version` with it and silently trigger a reseed.
     */
    private static function stray_field_options() {
        return array(
            'wcos_orders_per_hour',
            'wcos_products',
            'wcos_min_order_products',
            'wcos_max_order_products',
            'wcos_create_users',
            'wcos_order_completed_pct',
            'wcos_order_processing_pct',
            'wcos_order_failed_pct',
        );
    }

    /**
     * Delete the stray per-field options.
     *
     * @param bool $dry_run Report without deleting.
     *
     * @return array option name => current value, for those that exist.
     */
    public function clean_stray_options( $dry_run = false ) {
        $found = array();

        foreach ( self::stray_field_options() as $name ) {
            $value = get_option( $name, null );

            if ( null === $value ) {
                continue;
            }

            $found[ $name ] = $value;

            if ( ! $dry_run ) {
                delete_option( $name );
            }
        }

        return $found;
    }

    /**
     * Turn order generation off, or back on.
     *
     * Exists because the settings screen could not be trusted to do it: the field
     * rendered from a stray option rather than from the stored setting, so saving
     * wrote the stale number straight back.
     *
     * @param int $per_hour Orders per hour. 0 stops generation.
     *
     * @return int The value now stored.
     */
    public function set_orders_per_hour( $per_hour ) {
        $settings = self::get_settings();

        // Read-modify-write the whole array. Replacing the option outright would
        // drop the product selection and the status split back to defaults.
        $settings['orders_per_hour'] = max( 0, (int) $per_hour );

        update_option( 'wc_order_simulator_settings', $settings );

        $this->settings = $settings;

        return $settings['orders_per_hour'];
    }

    /**
     * How many customers still carry a given billing surname.
     *
     * Used to warn when reidentify-orders is about to faithfully copy the very
     * names it is supposed to be replacing.
     */
    public function count_customers_named( $last_name ) {
        return count( get_users( array(
            'role'       => 'Customer',
            'fields'     => 'ID',
            'meta_key'   => 'billing_last_name',
            'meta_value' => $last_name,
        ) ) );
    }

    /**
     * Measure the acceptance criteria from the defect report.
     *
     * @return array criterion => array(label, value, target, pass)
     */
    public function measure_realism() {
        global $wpdb;

        $addresses = $wpdb->prefix . 'wc_order_addresses';

        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$addresses}'" ) !== $addresses ) {
            return new WP_Error(
                'wcos_no_hpos',
                sprintf( '%s does not exist. These measurements assume HPOS.', $addresses )
            );
        }

        $totals = $wpdb->get_row(
            "SELECT COUNT(*)                   AS rows_total,
                    COUNT(DISTINCT last_name)  AS distinct_last,
                    COUNT(DISTINCT first_name) AS distinct_first,
                    COUNT(DISTINCT email)      AS distinct_email,
                    SUM(last_name = '')        AS empty_last,
                    SUM(first_name = '')       AS empty_first,
                    SUM(address_1 = '')        AS empty_address,
                    SUM(city = '')             AS empty_city,
                    COUNT(DISTINCT city)       AS distinct_city
             FROM   {$addresses}"
        );

        $rows = max( 1, (int) $totals->rows_total );

        $top = $wpdb->get_row(
            "SELECT last_name, COUNT(*) AS c
             FROM   {$addresses}
             WHERE  last_name <> ''
             GROUP  BY last_name
             ORDER  BY c DESC
             LIMIT  1"
        );

        $top_share = $top ? ( 100 * (int) $top->c / $rows ) : 0.0;

        // Criterion 4: an address whose email does not contain its own surname is
        // one where searching by name and searching by email disagree.
        //
        // Folded in PHP with the same ascii_slug() the generator uses, rather than
        // with a SQL LIKE. 106 of the 4,308 surnames carry a space, hyphen or
        // apostrophe and 206 rows carry an accent; a raw LIKE would report every
        // Balls-Headley and every Muller as a failure when the data is correct.
        $pairs = $wpdb->get_results(
            "SELECT DISTINCT last_name, email
             FROM   {$addresses}
             WHERE  last_name <> '' AND email <> ''"
        );

        $uncorrelated = 0;

        foreach ( (array) $pairs as $pair ) {
            $surname = $this->ascii_slug( $pair->last_name );

            if ( '' === $surname || false === strpos( strtolower( $pair->email ), $surname ) ) {
                $uncorrelated++;
            }
        }

        $empty_name_pct = 100 * max( (int) $totals->empty_last, (int) $totals->empty_first ) / $rows;

        $phones = $wpdb->get_row(
            "SELECT COUNT(DISTINCT phone) AS distinct_phone, SUM(phone = '') AS empty_phone
             FROM   {$addresses}"
        );

        $top_phone = $wpdb->get_row(
            "SELECT phone, COUNT(*) AS c
             FROM   {$addresses}
             WHERE  phone <> ''
             GROUP  BY phone
             ORDER  BY c DESC
             LIMIT  1"
        );

        $top_phone_share = $top_phone ? ( 100 * (int) $top_phone->c / $rows ) : 0.0;

        // Domain spread. A store where one domain holds everything is a store
        // where searching by domain is not a test of anything.
        $domain_rows = $wpdb->get_results(
            "SELECT SUBSTRING_INDEX(email, '@', -1) AS domain, COUNT(*) AS c
             FROM   {$addresses}
             WHERE  email <> ''
             GROUP  BY domain
             ORDER  BY c DESC"
        );

        $top_domain_share = $domain_rows ? ( 100 * (int) $domain_rows[0]->c / $rows ) : 0.0;

        // Anything outside the reserved set could carry mail off the box.
        $reserved   = self::email_domains();
        $escapable  = 0;

        foreach ( (array) $domain_rows as $domain_row ) {
            if ( ! isset( $reserved[ strtolower( $domain_row->domain ) ] ) ) {
                $escapable += (int) $domain_row->c;
            }
        }

        return array(
            'rows'          => array( 'label' => 'Address rows measured', 'value' => (int) $totals->rows_total, 'target' => '-', 'pass' => null ),
            'distinct_last' => array( 'label' => 'Distinct surnames', 'value' => (int) $totals->distinct_last, 'target' => '>= 2000', 'pass' => (int) $totals->distinct_last >= 2000 ),
            'empty_names'   => array( 'label' => 'Rows with an empty given or family name', 'value' => sprintf( '%.2f%%', $empty_name_pct ), 'target' => '< 1%', 'pass' => $empty_name_pct < 1.0 ),
            'top_share'     => array( 'label' => sprintf( 'Most common surname (%s)', $top ? $top->last_name : 'n/a' ), 'value' => sprintf( '%.2f%%', $top_share ), 'target' => '1-2%', 'pass' => $top_share >= 1.0 && $top_share <= 2.0 ),
            'email_match'   => array( 'label' => 'Distinct name/email pairs where the email omits the surname', 'value' => $uncorrelated, 'target' => '0', 'pass' => 0 === $uncorrelated ),
            'empty_address' => array( 'label' => 'Rows with an empty street address', 'value' => (int) $totals->empty_address, 'target' => '0', 'pass' => 0 === (int) $totals->empty_address ),
            'distinct_city' => array( 'label' => 'Distinct cities', 'value' => (int) $totals->distinct_city, 'target' => '> 100', 'pass' => (int) $totals->distinct_city > 100 ),
            'distinct_phone'=> array( 'label' => 'Distinct phone numbers', 'value' => (int) $phones->distinct_phone, 'target' => '>= 2000', 'pass' => (int) $phones->distinct_phone >= 2000 ),
            'top_phone'     => array( 'label' => 'Most common phone number', 'value' => sprintf( '%.2f%%', $top_phone_share ), 'target' => '< 1%', 'pass' => $top_phone_share < 1.0 ),
            'empty_phone'   => array( 'label' => 'Rows with an empty phone number', 'value' => (int) $phones->empty_phone, 'target' => '0', 'pass' => 0 === (int) $phones->empty_phone ),
            'domains'       => array( 'label' => 'Distinct email domains', 'value' => count( (array) $domain_rows ), 'target' => '> 1', 'pass' => count( (array) $domain_rows ) > 1 ),
            'top_domain'    => array( 'label' => 'Most common email domain', 'value' => sprintf( '%.1f%%', $top_domain_share ), 'target' => '< 60%', 'pass' => $top_domain_share < 60.0 ),
            'deliverable'   => array( 'label' => 'Rows on a domain that is not RFC-reserved', 'value' => $escapable, 'target' => '0', 'pass' => 0 === $escapable ),
            'distinct_first'=> array( 'label' => 'Distinct given names', 'value' => (int) $totals->distinct_first, 'target' => '-', 'pass' => null ),
            'distinct_email'=> array( 'label' => 'Distinct emails', 'value' => (int) $totals->distinct_email, 'target' => '-', 'pass' => null ),
        );
    }

}

/**
 * WP-CLI front end.
 *
 * The defect report states its acceptance criteria as numbers so the fix can be
 * verified rather than declared. `wp wcos verify` is that check.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {

    class WCOS_CLI_Command {

        private function simulator() {
            return $GLOBALS['wc_order_simulator'];
        }

        /**
         * Measure order address data against the realism acceptance criteria.
         *
         * ## EXAMPLES
         *
         *     wp wcos verify
         */
        public function verify( $args, $assoc_args ) {
            $results = $this->simulator()->measure_realism();

            if ( is_wp_error( $results ) ) {
                WP_CLI::error( $results->get_error_message() );
            }

            $rows   = array();
            $failed = 0;

            foreach ( $results as $result ) {
                if ( false === $result['pass'] ) {
                    $failed++;
                }

                $rows[] = array(
                    'measure' => $result['label'],
                    'value'   => (string) $result['value'],
                    'target'  => $result['target'],
                    'result'  => null === $result['pass'] ? '-' : ( $result['pass'] ? 'PASS' : 'FAIL' ),
                );
            }

            WP_CLI\Utils\format_items( 'table', $rows, array( 'measure', 'value', 'target', 'result' ) );

            if ( $failed ) {
                WP_CLI::warning( sprintf( '%d criteria failed.', $failed ) );
            } else {
                WP_CLI::success( 'All criteria met.' );
            }
        }

        /**
         * Load fakenames.sql into the fixture table.
         *
         * ## OPTIONS
         *
         * [--force]
         * : Truncate and reload even when the table already has rows.
         *
         * ## EXAMPLES
         *
         *     wp wcos seed --force
         */
        public function seed( $args, $assoc_args ) {
            $force  = isset( $assoc_args['force'] );
            $loaded = $this->simulator()->seed_fakenames( $force );

            if ( is_wp_error( $loaded ) ) {
                WP_CLI::error( $loaded->get_error_message() );
            }

            WP_CLI::success( sprintf( '%d fixture rows in place.', $loaded ) );
        }

        /**
         * Stop or start order generation.
         *
         * Use this rather than the settings screen on any install that has not yet
         * had the stray per-field options cleaned up, because there the field
         * rendered from the stray option and saving wrote the stale value back.
         *
         * ## OPTIONS
         *
         * [<per-hour>]
         * : Orders per hour. 0 stops generation. Omit to just report the setting.
         *
         * [--unschedule]
         * : Also clear the wcos_create_orders cron event. Note that reactivating
         *   the plugin re-adds it, whereas orders_per_hour = 0 survives.
         *
         * ## EXAMPLES
         *
         *     wp wcos generation            # report
         *     wp wcos generation 0          # stop
         *     wp wcos generation 0 --unschedule
         *     wp wcos generation 200        # resume
         */
        public function generation( $args, $assoc_args ) {
            if ( isset( $args[0] ) && '' !== $args[0] ) {
                $now = $this->simulator()->set_orders_per_hour( $args[0] );
                WP_CLI::success( sprintf( 'orders_per_hour is now %d.', $now ) );
            } else {
                $settings = WC_Order_Simulator::get_settings();
                WP_CLI::log( sprintf( 'orders_per_hour = %d', $settings['orders_per_hour'] ) );
            }

            if ( isset( $assoc_args['unschedule'] ) ) {
                $cleared = 0;
                while ( $next = wp_next_scheduled( 'wcos_create_orders' ) ) {
                    wp_unschedule_event( $next, 'wcos_create_orders' );
                    $cleared++;
                    if ( $cleared > 50 ) {
                        break;
                    }
                }
                WP_CLI::success( sprintf( 'Cleared %d scheduled wcos_create_orders event(s).', $cleared ) );
            }

            $next = wp_next_scheduled( 'wcos_create_orders' );

            WP_CLI::log( $next
                ? sprintf( 'Next scheduled run: %s GMT', gmdate( 'Y-m-d H:i:s', $next ) )
                : 'No run scheduled.'
            );
        }

        /**
         * Delete the stray per-field options that break the settings screen.
         *
         * WooCommerce treats a settings field `id` as an option name, writes those
         * options, and then renders the field from them instead of from the real
         * stored setting. That is why edits appeared to revert. 1.2.1 pins each
         * field to its stored value so the render is correct regardless, but the
         * stray options are still litter worth removing.
         *
         * ## OPTIONS
         *
         * [--dry-run]
         * : Report what exists without deleting.
         *
         * ## EXAMPLES
         *
         *     wp wcos clean-options --dry-run
         *     wp wcos clean-options
         *
         * @subcommand clean-options
         */
        public function clean_options( $args, $assoc_args ) {
            $dry_run = isset( $assoc_args['dry-run'] );
            $found   = $this->simulator()->clean_stray_options( $dry_run );

            if ( ! $found ) {
                WP_CLI::success( 'No stray per-field options present.' );
                return;
            }

            foreach ( $found as $name => $value ) {
                WP_CLI::log( sprintf( '  %s = %s', $name, is_scalar( $value ) ? $value : wp_json_encode( $value ) ) );
            }

            WP_CLI::success( sprintf(
                '%d stray option(s) %s. The plugin reads none of them; wc_order_simulator_settings is the real store.',
                count( $found ),
                $dry_run ? 'found (dry run)' : 'deleted'
            ) );
        }

        /**
         * Copy each order's customer identity onto the order itself.
         *
         * Repairs orders that already exist instead of deleting and regenerating
         * them: dates, totals, line items and status mix are kept, and only the
         * identity columns are rewritten. Run `wp wcos refresh-customers` first,
         * or this will faithfully copy the names you are trying to replace.
         *
         * Batched and resumable. Each invocation processes --batch orders and
         * remembers where it stopped, so a process killed by the host picks up
         * rather than starting over. Re-run until it reports 0 examined.
         *
         * ## OPTIONS
         *
         * [--dry-run]
         * : Report what would change without writing. Also prints which customer
         *   IDs sit behind the orders, which is the quickest way to see what the
         *   blank-address orders were actually attached to.
         *
         * [--batch=<n>]
         * : Orders to process this invocation. Default 2000.
         *
         * [--reset]
         * : Forget the stored resume point and start from the first order.
         *
         * [--all]
         * : Keep going until every order has been processed, rather than stopping
         *   after one batch. Only do this where the host will not kill it.
         *
         * [--yes]
         * : Skip the confirmation prompt.
         *
         * ## EXAMPLES
         *
         *     wp wcos reidentify-orders --dry-run
         *     wp wcos reidentify-orders --batch=2000 --yes
         *     wp wcos reidentify-orders --all --yes
         *
         * @subcommand reidentify-orders
         */
        public function reidentify_orders( $args, $assoc_args ) {
            $dry_run = isset( $assoc_args['dry-run'] );
            $batch   = isset( $assoc_args['batch'] ) ? max( 1, absint( $assoc_args['batch'] ) ) : 2000;
            $all     = isset( $assoc_args['all'] );

            if ( isset( $assoc_args['reset'] ) ) {
                delete_option( 'wcos_reidentify_after_id' );
                WP_CLI::log( 'Resume point cleared.' );
            }

            $stale = $this->simulator()->count_customers_named( 'Example' );

            if ( $stale > 0 ) {
                WP_CLI::warning( sprintf(
                    '%d customers are still named "Example". This command copies what the customer record says, so run `wp wcos refresh-customers --last-name=Example` first.',
                    $stale
                ) );
            }

            if ( ! $dry_run ) {
                // Blocks mail for this process only. Saving tens of thousands of
                // orders should not fire customer email, and "should not" is not
                // the standard to hold a one-way operation to.
                add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );

                WP_CLI::confirm(
                    sprintf( 'Rewrite identity fields on orders, in batches of %d. Continue?', $batch ),
                    $assoc_args
                );
            }

            $after_id = (int) get_option( 'wcos_reidentify_after_id', 0 );
            $totals   = array( 'examined' => 0, 'updated' => 0, 'skipped' => 0, 'from_guest' => 0, 'filled_blank' => 0 );
            $buckets  = array();
            $samples  = array();

            do {
                $stats = $this->simulator()->reidentify_orders( $batch, $after_id, $dry_run );

                foreach ( array_keys( $totals ) as $key ) {
                    $totals[ $key ] += $stats[ $key ];
                }

                foreach ( $stats['customer_ids'] as $bucket => $count ) {
                    $buckets[ $bucket ] = ( $buckets[ $bucket ] ?? 0 ) + $count;
                }

                if ( ! $samples ) {
                    $samples = $stats['samples'];
                }

                if ( ! $stats['examined'] ) {
                    break;
                }

                $after_id = $stats['last_id'];

                if ( ! $dry_run ) {
                    update_option( 'wcos_reidentify_after_id', $after_id, false );
                }

                WP_CLI::log( sprintf(
                    '  ... %d examined, %d updated, through order #%d',
                    $totals['examined'],
                    $totals['updated'],
                    $after_id
                ) );
            } while ( $all );

            if ( $samples ) {
                WP_CLI::log( '' );
                WP_CLI::log( 'Sample of the change:' );
                foreach ( $samples as $sample ) {
                    WP_CLI::log( '  ' . $sample );
                }
            }

            if ( $buckets ) {
                WP_CLI::log( '' );
                WP_CLI::log( 'Customer link on the orders seen: ' . http_build_query( $buckets, '', ', ' ) );
            }

            WP_CLI::log( '' );

            $summary = sprintf(
                '%d examined, %d %s, %d had a blank address filled in, %d had no usable customer and got a fresh identity, %d skipped.',
                $totals['examined'],
                $dry_run ? $totals['examined'] - $totals['skipped'] : $totals['updated'],
                $dry_run ? 'would be updated' : 'updated',
                $totals['filled_blank'],
                $totals['from_guest'],
                $totals['skipped']
            );

            if ( $dry_run ) {
                WP_CLI::success( 'Dry run. ' . $summary );
                return;
            }

            WP_CLI::success( $summary );

            if ( ! $all && $totals['examined'] ) {
                WP_CLI::log( sprintf(
                    'Resume point saved at order #%d. Re-run to continue, or pass --all.',
                    $after_id
                ) );
            }
        }

        /**
         * Give existing customers fresh synthetic names.
         *
         * Orders already written are not rewritten - this only changes what future
         * orders inherit, and what the customer record itself says.
         *
         * ## OPTIONS
         *
         * [--last-name=<name>]
         * : Only touch customers with this billing surname. Use this to clean up
         *   the customers left behind by the "Example" fixture.
         *
         * [--limit=<n>]
         * : Maximum customers to touch.
         *
         * ## EXAMPLES
         *
         *     wp wcos refresh-customers --last-name=Example
         *
         * @subcommand refresh-customers
         */
        public function refresh_customers( $args, $assoc_args ) {
            $last_name = isset( $assoc_args['last-name'] ) ? $assoc_args['last-name'] : '';
            $limit     = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 0;

            $result = $this->simulator()->refresh_customer_identities( $last_name, $limit );

            WP_CLI::success( sprintf(
                '%d customers re-identified, %d skipped.',
                $result['updated'],
                $result['skipped']
            ) );
        }
    }
}

$GLOBALS['wc_order_simulator'] = new WC_Order_Simulator();
