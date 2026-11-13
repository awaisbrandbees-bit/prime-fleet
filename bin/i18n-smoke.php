<?php
if ( ! class_exists( 'Prime_Fleet\I18n\Script_Strings' ) ) {
	WP_CLI::error( 'Script_Strings missing' );
}
$b = \Prime_Fleet\I18n\Script_Strings::booking();
$a = \Prime_Fleet\I18n\Script_Strings::admin();
WP_CLI::success( 'booking=' . count( $b ) . ' admin=' . count( $a ) . ' title=' . $b['title'] . ' saved=' . $a['saved'] );
WP_CLI::log( 'textdomain loaded: ' . ( is_textdomain_loaded( 'prime-fleet' ) ? 'yes' : 'no' ) );
WP_CLI::log( 'pot exists: ' . ( file_exists( PRIME_FLEET_PATH . 'languages/prime-fleet.pot' ) ? 'yes' : 'no' ) );
