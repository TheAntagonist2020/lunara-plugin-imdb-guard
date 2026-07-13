<?php
/**
 * Regression coverage for the Review header lookup fallback.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['lunara_test_content'] = array();
$GLOBALS['lunara_test_meta']    = array();
$GLOBALS['lunara_test_titles']  = array();
$GLOBALS['lunara_test_transient_key'] = '';

function plugin_dir_path( string $file ): string {
	return trailingslashit( dirname( $file ) );
}

function trailingslashit( string $path ): string {
	return rtrim( $path, '/\\' ) . '/';
}

function add_action(): void {
}

function get_post_field( string $field, int $post_id ) {
	if ( 'post_content' !== $field ) {
		return '';
	}

	return $GLOBALS['lunara_test_content'][ $post_id ] ?? '';
}

function absint( $value ): int {
	return abs( (int) $value );
}

function get_post_meta( int $post_id, string $key, bool $single = false ) {
	return $GLOBALS['lunara_test_meta'][ $post_id ][ $key ] ?? '';
}

function get_the_title( int $post_id ): string {
	return $GLOBALS['lunara_test_titles'][ $post_id ] ?? '';
}

function wp_strip_all_tags( string $text ): string {
	return strip_tags( $text );
}

function get_transient( string $key ): bool {
	$GLOBALS['lunara_test_transient_key'] = $key;
	return false;
}

function get_option( string $option, $default = false ) {
	return $default;
}

function __( string $text ): string {
	return $text;
}

function current_time(): string {
	return '2026-07-12 12:00:00';
}

function sanitize_text_field( $value ): string {
	return trim( strip_tags( (string) $value ) );
}

function update_post_meta( int $post_id, string $key, $value ): void {
	$GLOBALS['lunara_test_meta'][ $post_id ][ $key ] = $value;
}

require dirname( __DIR__ ) . '/lunara-imdb-guard.php';

function invoke_private( object $object, string $method, array $arguments = array() ) {
	$reflection = new ReflectionMethod( $object, $method );

	return $reflection->invokeArgs( $object, $arguments );
}

function assert_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		fwrite(
			STDERR,
			sprintf(
				"FAIL: %s\nExpected: %s\nActual: %s\n",
				$message,
				var_export( $expected, true ),
				var_export( $actual, true )
			)
		);
		exit( 1 );
	}
}

$guard = Lunara_IMDb_Guard::instance();

$header_cases = array(
	'canonical IMDb suffix' => array(
		'content' => "<!-- Oppenheimer (2023) | IMDb: tt15398776 -->\n<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->",
		'title'   => 'Oppenheimer',
	),
	'straight quotes' => array(
		'content' => '<!-- "Oppenheimer" (2023) -->',
		'title'   => 'Oppenheimer',
	),
	'smart quotes' => array(
		'content' => '<!-- “Oppenheimer” (2023) -->',
		'title'   => 'Oppenheimer',
	),
);

$post_id = 100;
foreach ( $header_cases as $label => $case ) {
	$GLOBALS['lunara_test_content'][ $post_id ] = $case['content'];
	$context = invoke_private( $guard, 'extract_review_header_context', array( $post_id ) );
	assert_same( $case['title'], $context['title'], $label . ' title' );
	assert_same( '2023', $context['year'], $label . ' year' );
	$post_id++;
}

$gutenberg_post = 200;
$GLOBALS['lunara_test_content'][ $gutenberg_post ] = "<!-- wp:paragraph -->\n<p>Ordinary Gutenberg content.</p>\n<!-- /wp:paragraph -->";
$warnings = array();
set_error_handler(
	static function ( int $severity, string $message ) use ( &$warnings ): bool {
		$warnings[] = array( $severity, $message );
		return true;
	}
);
$empty_context = invoke_private( $guard, 'extract_review_header_context', array( $gutenberg_post ) );
restore_error_handler();
assert_same( array( 'title' => '', 'year' => '' ), $empty_context, 'ordinary Gutenberg content does not match' );
assert_same( array(), $warnings, 'ordinary Gutenberg content emits no warnings' );

$fallback_post = 300;
$GLOBALS['lunara_test_content'][ $fallback_post ] = $GLOBALS['lunara_test_content'][ $gutenberg_post ];
$GLOBALS['lunara_test_titles'][ $fallback_post ]  = 'The Review Title (2024)';
$fallback = invoke_private( $guard, 'resolve_lookup_context', array( $fallback_post ) );
assert_same( 'The Review Title', $fallback['title'], 'resolver falls back to the post title' );
assert_same( '', $fallback['year'], 'post-title fallback does not invent a release year' );
assert_same( 'post_title_trimmed', $fallback['source'], 'resolver reports the trimmed post-title source' );

$header_year_post = 400;
$GLOBALS['lunara_test_content'][ $header_year_post ] = '<!-- Oppenheimer (2023) | IMDb: tt15398776 -->';
$GLOBALS['lunara_test_titles'][ $header_year_post ]  = 'Different Editorial Headline';
$GLOBALS['lunara_test_transient_key'] = '';
$validation = invoke_private( $guard, 'validate_review', array( $header_year_post, false ) );
assert_same( 'error', $validation['status'], 'header year satisfies validation when _lunara_year is missing' );
assert_same(
	'lunara_imdb_guard_v2_' . md5( 'oppenheimer|2023' ),
	$GLOBALS['lunara_test_transient_key'],
	'validation uses the Review header year in its OMDb lookup key'
);
assert_same(
	'No OMDb API key is configured for Lunara IMDb Guard.',
	$validation['message'],
	'validation reaches OMDb configuration after using the header year'
);

fwrite( STDOUT, "Review header context regression passed.\n" );
