<?php
/**
 * Plugin Name: Enhanced Debug Settings
 * Description: Override debug settings with beautiful error formatting and capture dumps from var_dump, print_r, and var_export.
 * Version: 1.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Captures output from various PHP debugging functions and stores it in a global array.
 *
 * @param mixed  $data   The variable to capture and debug.
 * @param string $method The debugging function to use ('var_dump', 'print_r', or 'var_export').
 * @return void
 */
function debug_capture( $data, $method = 'var_dump' ) {
	ob_start();
	switch ( $method ) {
		case 'print_r':
			print_r( $data );
			break;
		case 'var_export':
			var_export( $data );
			break;
		case 'var_dump':
		default:
			var_dump( $data );
			break;
	}
	$output = ob_get_clean();

	// Use backtrace to capture file and line info for the caller of debug_capture.
	$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 2 );
	$file = isset( $trace[1]['file'] ) ? $trace[1]['file'] : '';
	$line = isset( $trace[1]['line'] ) ? $trace[1]['line'] : '';

	global $wp_debug_errors;
	if ( ! isset( $wp_debug_errors['dumps'] ) ) {
		$wp_debug_errors['dumps'] = array();
	}
	// Store the raw output along with caller file and line.
	$wp_debug_errors['dumps'][] = array(
		'timestamp' => date( 'Y-m-d H:i:s' ),
		'message' => $output,
		'file' => $file,
		'line' => $line,
		'type' => 'DEBUG_DUMP'
	);
}

// Initialize error storage immediately
global $wp_debug_errors;
$wp_debug_errors = array(
	'errors' => array(),
	'warnings' => array(),
	'notices' => array(),
	'dumps' => array()
);

// Force error reporting but suppress default display
error_reporting( E_ALL );
ini_set( 'display_errors', 0 );
ini_set( 'log_errors', 1 );
ini_set( 'error_log', WP_CONTENT_DIR . '/debug.log' );

// Create debug.log if it doesn't exist
if ( ! file_exists( WP_CONTENT_DIR . '/debug.log' ) ) {
	touch( WP_CONTENT_DIR . '/debug.log' );
	chmod( WP_CONTENT_DIR . '/debug.log', 0666 );
}

/**
 * Class DebugManager
 *
 * Manages debug functionality including error handling, admin bar integration, and debug panel rendering.
 */
class DebugManager {
	/**
	 * @var callable Error logging function
	 */
	private $error_logger;

	/**
	 * Constructor for DebugManager.
	 *
	 * @param callable|null $error_logger Optional custom error logging function.
	 */
	public function __construct( callable $error_logger = null ) {
		$this->error_logger = $error_logger ?: 'error_log';
	}

	/**
	 * Initializes the debug manager by setting up error handlers and WordPress hooks.
	 *
	 * @return void
	 */
	public function init() {
		// Set custom error handler using instance method.
		set_error_handler( [ $this, 'enhancedErrorHandler' ] );

		// Register hooks with static methods.
		add_action( 'init', [ self::class, 'showAdminBarForDebug' ] );
		add_action( 'admin_bar_menu', [ self::class, 'addDebugAdminBarMenu' ], 100 );
		add_action( 'admin_head', [ self::class, 'addDebugStyles' ] );
		add_action( 'wp_head', [ self::class, 'addDebugStyles' ] );
		if ( is_admin() ) {
			add_action( 'admin_footer', [ self::class, 'renderDebugPanels' ] );
		} else {
			add_action( 'wp_footer', [ self::class, 'renderDebugPanels' ] );
		}
	}

	/**
	 * Initializes the global error storage array.
	 *
	 * @return void
	 */
	public static function initErrorStorage() {
		global $wp_debug_errors;
		if ( ! is_array( $wp_debug_errors ) ) {
			$wp_debug_errors = array(
				'errors' => array(),
				'warnings' => array(),
				'notices' => array(),
				'dumps' => array()
			);
		}
	}

	/**
	 * Converts PHP error type constants to human-readable strings.
	 *
	 * @param int $type PHP error type constant
	 * @return string Human-readable error type name
	 */
	public static function getErrorTypeName( $type ) {
		switch ( $type ) {
			case E_ERROR:
				return 'E_ERROR';
			case E_WARNING:
				return 'E_WARNING';
			case E_PARSE:
				return 'E_PARSE';
			case E_NOTICE:
				return 'E_NOTICE';
			case E_CORE_ERROR:
				return 'E_CORE_ERROR';
			case E_CORE_WARNING:
				return 'E_CORE_WARNING';
			case E_COMPILE_ERROR:
				return 'E_COMPILE_ERROR';
			case E_COMPILE_WARNING:
				return 'E_COMPILE_WARNING';
			case E_USER_ERROR:
				return 'E_USER_ERROR';
			case E_USER_WARNING:
				return 'E_USER_WARNING';
			case E_USER_NOTICE:
				return 'E_USER_NOTICE';
			case E_STRICT:
				return 'E_STRICT';
			case E_RECOVERABLE_ERROR:
				return 'E_RECOVERABLE_ERROR';
			case E_DEPRECATED:
				return 'E_DEPRECATED';
			case E_USER_DEPRECATED:
				return 'E_USER_DEPRECATED';
			default:
				return 'UNKNOWN';
		}
	}

	/**
	 * Formats error log messages with visual separators and timestamps.
	 *
	 * @param array $error_data Array containing error details
	 * @return string Formatted log message
	 */
	public static function formatLogMessage( $error_data ) {
		$separator = str_repeat( '-', 40 );
		return sprintf(
			"[%s]\n[!] %s in %s on line %d\n%s\n\n[>] Stack trace:\n%s\n%s\n",
			$error_data['timestamp'],
			$error_data['message'],
			$error_data['file'],
			$error_data['line'],
			$separator,
			$error_data['stack'],
			$separator
		);
	}

	/**
	 * Generates a formatted string representation of the debug backtrace.
	 *
	 * @return string Formatted backtrace
	 */
	public static function getDebugBacktraceString() {
		$stack = debug_backtrace();
		$output = '';
		foreach ( $stack as $i => $trace ) {
			if ( $i < 2 ) {
				continue; // Skip error handler frames.
			}
			$output .= "#{$i} ";
			$output .= isset( $trace['file'] ) ? $trace['file'] : '[internal function]';
			$output .= isset( $trace['line'] ) ? "({$trace['line']}): " : ' ';
			$output .= isset( $trace['class'] ) ? $trace['class'] . $trace['type'] : '';
			$output .= $trace['function'] . "()\n";
		}
		return $output;
	}

	/**
	 * Custom error handler that captures and formats error information.
	 *
	 * @param int    $errno   Error level
	 * @param string $errstr  Error message
	 * @param string $errfile File where the error occurred
	 * @param int    $errline Line number where the error occurred
	 * @return bool False to allow error propagation
	 */
	public function enhancedErrorHandler( $errno, $errstr, $errfile, $errline ) {
		self::initErrorStorage();
		$error_type = self::getErrorTypeName( $errno );
		$timestamp = date( 'Y-m-d H:i:s' );

		$error_data = array(
			'message' => $errstr,
			'file' => $errfile,
			'line' => $errline,
			'type' => $error_type,
			'timestamp' => $timestamp,
			'stack' => self::getDebugBacktraceString()
		);

		global $wp_debug_errors;
		if ( strpos( $error_type, 'ERROR' ) !== false ) {
			array_unshift( $wp_debug_errors['errors'], $error_data );
		} elseif ( strpos( $error_type, 'WARNING' ) !== false ) {
			array_unshift( $wp_debug_errors['warnings'], $error_data );
		} else {
			array_unshift( $wp_debug_errors['notices'], $error_data );
		}

		$log_message = self::formatLogMessage( $error_data );
		call_user_func( $this->error_logger, $log_message );
		return false;
	}

	/**
	 * Adds debug information to the WordPress admin bar.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar WordPress admin bar object
	 * @return void
	 */
	public static function addDebugAdminBarMenu( $wp_admin_bar ) {
		self::initErrorStorage();
		$errors_count = count( $GLOBALS['wp_debug_errors']['errors'] );
		$warnings_count = count( $GLOBALS['wp_debug_errors']['warnings'] );
		$notices_count = count( $GLOBALS['wp_debug_errors']['notices'] );
		$dumps_count = count( $GLOBALS['wp_debug_errors']['dumps'] );
		$total_count = $errors_count + $warnings_count + $notices_count + $dumps_count;

		if ( $total_count === 0 ) {
			return;
		}

		$color_class = $errors_count > 0 ? 'error' : ( $warnings_count > 0 ? 'warning' : ( $notices_count > 0 ? 'notice' : 'dumps' ) );
		$wp_admin_bar->add_node( array(
			'id' => 'debug-errors',
			'title' => sprintf(
				'<span class="debug-counter %s" onclick="toggleAllErrorPanels(); return false;">Debug (%d)</span>',
				$color_class,
				$total_count
			),
			'href' => '#'
		) );

		if ( $errors_count > 0 ) {
			self::addErrorSubmenu( $wp_admin_bar, 'errors', 'Errors', $errors_count );
		}
		if ( $warnings_count > 0 ) {
			self::addErrorSubmenu( $wp_admin_bar, 'warnings', 'Warnings', $warnings_count );
		}
		if ( $notices_count > 0 ) {
			self::addErrorSubmenu( $wp_admin_bar, 'notices', 'Notices', $notices_count );
		}
		if ( $dumps_count > 0 ) {
			self::addErrorSubmenu( $wp_admin_bar, 'dumps', 'Dumps', $dumps_count );
		}
	}

	/**
	 * Adds a submenu item to the debug menu in the admin bar.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar WordPress admin bar object
	 * @param string       $type         Error type identifier
	 * @param string       $label        Display label for the menu item
	 * @param int          $count        Number of items of this type
	 * @return void
	 */
	public static function addErrorSubmenu( $wp_admin_bar, $type, $label, $count ) {
		$wp_admin_bar->add_node( array(
			'id' => "debug-$type",
			'parent' => 'debug-errors',
			'title' => sprintf( '%s (%d)', $label, $count ),
			'href' => '#',
			'meta' => array( 'onclick' => sprintf( 'toggleErrorPanel("%s"); return false;', $type ) )
		) );
	}

	/**
	 * Outputs CSS and JavaScript for debug panels styling and functionality.
	 *
	 * @return void
	 */
	public static function addDebugStyles() {
		?>
		<style>
			:root {
				--color-error: hsl(354, 70.50%, 53.50%);
				--color-warning: hsl(45, 100.00%, 51.40%);
				--color-notice: hsl(190, 89.70%, 49.60%);
				--color-dumps: hsl(300, 50%, 50%);
				--text-light: #fff;
				--text-dark: #000;
				--bg-light: hsl(0, 0%, 90.60%);
				--bg-dark: hsl(250, 5.50%, 21.60%);
			}

			#wpadminbar .debug-counter {
				display: inline-block;
				padding: 0 5px;
				margin-left: 5px;
			}

			#wpadminbar .debug-counter.error {
				background: var(--color-error);
				color: var(--text-light);
			}

			#wpadminbar .debug-counter.warning {
				background: var(--color-warning);
				color: var(--text-dark);
			}

			#wpadminbar .debug-counter.notice {
				background: var(--color-notice);
				color: var(--text-dark);
			}

			#wpadminbar .debug-counter.dumps {
				background: var(--color-dumps);
				color: var(--text-light);
			}

			#debug-container {
				position: fixed;
				top: 32px;
				right: 10px;
				width: 400px;
				max-height: calc(100vh - 32px);
				overflow-y: auto;
				z-index: 99999;
				display: none;
			}

			.debug-panel {
				margin-bottom: 10px;
				padding: 10px;
				background: var(--bg-dark);
				border-left: 4px solid;
			}

			.debug-panel.errors {
				border-color: var(--color-error);
			}

			.debug-panel.warnings {
				border-color: var(--color-warning);
			}

			.debug-panel.notices {
				border-color: var(--color-notice);
			}

			.debug-panel.dumps {
				border-color: var(--color-dumps);
			}

			.debug-item {
				padding: 15px;
				margin-bottom: 10px;
				background-color: var(--bg-dark);
				border-bottom: 1px solid #eee;
			}

			.debug-item:last-child {
				border-bottom: none;
			}

			.debug-indicator {
				display: inline-block;
				width: 12px;
				height: 12px;
				border-radius: 50%;
				margin-right: 5px;
			}

			.debug-panel.errors .debug-indicator {
				background: var(--color-error);
			}

			.debug-panel.warnings .debug-indicator {
				background: var(--color-warning);
			}

			.debug-panel.notices .debug-indicator {
				background: var(--color-notice);
			}

			.debug-panel.dumps .debug-indicator {
				background: var(--color-dumps);
			}

			.debug-item .timestamp {
				color: var(--text-light);
				font-size: 12px;
			}

			/* Show message in a <pre> block */
			.debug-item .message {
				font-family: monospace;
				white-space: pre-wrap;
				margin: 5px 0;
				font-weight: bold;
				background: var(--bg-light);
				border-radius: 8px;
				padding: 10px;
			}

			/* Show file path and details in a normal div */
			.debug-item .details {
				color: var(--text-light);
				background: var(--bg-dark);
				font-size: 12px;
				margin-top: 5px;
				padding: 10px;
				border-radius: 3px;
				white-space: normal;
			}

			@media screen and (max-width: 1680px) {
				#debug-container {
					width: 33vw;
					right: 10px;
				}
			}

			@media screen and (max-width: 782px) {
				#debug-container {
					top: 46px;
					width: 100%;
					right: 0 !important;
					left: 0;
				}
			}
		</style>
		<script>
			document.addEventListener('DOMContentLoaded', () => {
				// Toggle all panels visibility.
				window.toggleAllErrorPanels = () => {
					const container = document.getElementById('debug-container');
					if (container.style.display === 'none' || container.style.display === '') {
						container.style.display = 'block';
						// Show all panels.
						const panels = container.querySelectorAll('.debug-panel');
						panels.forEach(panel => panel.style.display = 'block');
					} else {
						container.style.display = 'none';
					}
				};
				// Toggle filter by type: if already filtered, show all; else show only target type.
				window.toggleErrorPanel = (type) => {
					const container = document.getElementById('debug-container');
					if (!container) return;
					const panels = container.querySelectorAll('.debug-panel');
					const target = container.querySelector('.debug-panel.' + type);
					// Check if currently filtered (only target visible).
					let onlyTargetVisible = true;
					panels.forEach(panel => {
						if (panel !== target && panel.style.display !== 'none') {
							onlyTargetVisible = false;
						}
					});
					if (!onlyTargetVisible) {
						// Filter to show only target.
						panels.forEach(panel => panel.style.display = 'none');
						target.style.display = 'block';
					} else {
						// Show all panels.
						panels.forEach(panel => panel.style.display = 'block');
					}
				};
			});
		</script>
		<?php
	}

	/**
	 * Renders the debug panels containing errors, warnings, notices, and dumps.
	 *
	 * @return void
	 */
	public static function renderDebugPanels() {
		global $wp_debug_errors;
		$errors = isset( $wp_debug_errors ) ? $wp_debug_errors : array(
			'errors' => array(),
			'warnings' => array(),
			'notices' => array(),
			'dumps' => array()
		);
		echo '<div id="debug-container">';
		foreach ( array( 'errors', 'warnings', 'notices', 'dumps' ) as $type ) {
			if ( ! empty( $errors[ $type ] ) ) {
				echo '<div class="debug-panel ' . esc_attr( $type ) . '">';
				foreach ( $errors[ $type ] as $error ) {
					echo '<div class="debug-item">';
					echo '<span class="debug-indicator"></span>';
					echo '<div class="timestamp">' . esc_html( $error['timestamp'] ) . '</div>';
					// Output message in <pre> tags.
					echo '<pre class="message">' . esc_html( $error['message'] ) . '</pre>';
					// Output file info and details in a normal div.
					echo '<div class="details">File: ' . esc_html( $error['file'] ) . '<br>Line: ' . esc_html( $error['line'] ) . '<br>Type: ' . esc_html( $error['type'] ) . '</div>';
					echo '</div>';
				}
				echo '</div>';
			}
		}
		echo '</div>';
	}

	/**
	 * Forces the admin bar to show for logged-in users when debug is active.
	 *
	 * @return void
	 */
	public static function showAdminBarForDebug() {
		if ( ! is_user_logged_in() ) {
			return;
		}
		add_filter( 'show_admin_bar', '__return_true' );
	}
}

// Initialize the DebugManager.
$debugManager = new DebugManager();
$debugManager->init();

/**
 * Prevents WordPress from displaying errors directly by setting WP_DEBUG_DISPLAY to false.
 *
 * @return void
 */
function prevent_default_error_display() {
	if ( ! defined( 'WP_DEBUG_DISPLAY' ) ) {
		define( 'WP_DEBUG_DISPLAY', false );
	}
}
add_action( 'init', 'prevent_default_error_display', 1 );
