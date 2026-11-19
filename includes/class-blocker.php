<?php
/**
 * Output buffer blocker.
 *
 * @package RevolterCookies
 */

namespace RevolterCookies;

defined( 'ABSPATH' ) || exit;

/**
 * Rewrites front-end HTML pages through an output buffer.
 */
class Blocker {

	/**
	 * Returns the Html_Rewriter, built on first use.
	 *
	 * @var callable
	 */
	private $rewriter_factory;

	/**
	 * Returns the response header lines.
	 *
	 * @var callable
	 */
	private $headers;

	/**
	 * Whether this buffer has shown the start of an HTML document.
	 *
	 * @var bool
	 */
	private $seen_html = false;

	/**
	 * Unfinished tail of the previous chunk, rewritten with the next one.
	 *
	 * @var string
	 */
	private $carry = '';

	/**
	 * Constructor.
	 *
	 * @param callable      $rewriter_factory Returns an Html_Rewriter.
	 * @param callable|null $headers          Returns header lines, default headers_list().
	 */
	public function __construct( callable $rewriter_factory, ?callable $headers = null ) {
		$this->rewriter_factory = $rewriter_factory;
		$this->headers          = null !== $headers ? $headers : 'headers_list';
	}

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'start' ), -1000 );
	}

	/**
	 * Start buffering on front-end pages.
	 */
	public function start(): void {
		if ( Context::is_frontend_page() ) {
			ob_start( array( $this, 'filter_output' ) );
		}
	}

	/**
	 * Output buffer callback. Never throws; anything but an HTML page passes through unchanged.
	 *
	 * The buffer may arrive in chunks when something flushes early. Once a chunk with <html has been
	 * seen, later chunks are rewritten too. A tag or a script/iframe element cut off at the end of a
	 * chunk is held back and rewritten with the next chunk, so it is never sent half-processed.
	 *
	 * @param string $buffer Buffer.
	 * @param int    $phase  PHP_OUTPUT_HANDLER_* flags.
	 * @return string
	 */
	public function filter_output( string $buffer, int $phase = PHP_OUTPUT_HANDLER_FINAL ): string {
		if ( ( $phase & PHP_OUTPUT_HANDLER_CLEAN ) && ! ( $phase & PHP_OUTPUT_HANDLER_FINAL ) ) {
			// ob_clean(): PHP discards this output, so the held-back tail goes with it.
			$this->carry = '';
			return $buffer;
		}
		$final       = (bool) ( $phase & PHP_OUTPUT_HANDLER_FINAL );
		$input       = $this->carry . $buffer;
		$this->carry = '';
		try {
			if ( ! $this->seen_html && false !== stripos( $input, '<html' ) ) {
				$this->seen_html = true;
			}
			if ( ! $this->seen_html || ! self::is_html_headers( (array) call_user_func( $this->headers ) ) ) {
				$out = $input;
			} else {
				$rewriter = call_user_func( $this->rewriter_factory );
				if ( ! $rewriter instanceof Html_Rewriter ) {
					$out = $input;
				} else {
					$cut         = $final ? strlen( $input ) : self::safe_cut( $input );
					$this->carry = (string) substr( $input, $cut );
					$out         = $rewriter->rewrite( (string) substr( $input, 0, $cut ) );
				}
			}
		} catch ( \Throwable $e ) {
			// $input already holds any tail held back above; send it once, unchanged.
			$out         = $input;
			$this->carry = '';
		}
		if ( $final ) {
			$this->seen_html = false;
			$out            .= $this->carry;
			$this->carry     = '';
		}
		return $out;
	}

	/**
	 * Length of the part of a chunk that can be rewritten now: up to an unfinished tag at the end,
	 * or up to a SCRIPT or IFRAME element that has not closed yet.
	 *
	 * @param string $html Chunk.
	 * @return int
	 */
	private static function safe_cut( string $html ): int {
		$cut = strlen( $html );
		$lt  = strrpos( $html, '<' );
		if ( false !== $lt && false === strpos( $html, '>', $lt ) ) {
			$cut = $lt;
		}
		foreach ( array( 'script', 'iframe' ) as $tag ) {
			$open  = strripos( $html, '<' . $tag );
			$close = strripos( $html, '</' . $tag );
			if ( false !== $open && ( false === $close || $close < $open ) ) {
				$cut = min( $cut, $open );
			}
		}
		return $cut;
	}

	/**
	 * Whether no Content-Type header says anything other than text/html.
	 *
	 * @param array $headers Header lines.
	 * @return bool
	 */
	public static function is_html_headers( array $headers ): bool {
		foreach ( $headers as $header ) {
			$header = (string) $header;
			if ( 0 !== stripos( $header, 'content-type:' ) ) {
				continue;
			}
			$value = explode( ';', substr( $header, strlen( 'content-type:' ) ) );
			if ( 'text/html' !== strtolower( trim( $value[0] ) ) ) {
				return false;
			}
		}
		return true;
	}
}
