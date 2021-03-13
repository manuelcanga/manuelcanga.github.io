<?php declare( strict_types = 1 );

/**
 * Class Parser. Analyze a text and execute specific code.
 * Allow code: {{var}}, {% block %}{% endblock %}, {'string'} and {$ hook }
 *
 * @package Framework
 */
final class Parser {

	private const FIND_VAR     = '/{{(?P<var>.*?)}}/';

	private const FIND_BLOCKS  = '/' .                                   //start regex
	                             '\{\%\s?(?<block_name>[\w\!\_\.]+)' .   //start block:  {% block_name
	                             '(\s(?<var>[^{}]*))?' .                 // var ?
	                             '\ \%\}' .                              // %}
	                             '(?<content>(([^%]+)|(?R))*)' .         //content with not %
	                             '\{\%\s?end\1\s?\%\}' .                 //end macro:  {% end{macro_name} %}
	                             '/mU';                                  //end regex

	private const VAR_ASSIGN   = '/\<meta data-app=\"(?P<var_name>.*?)\" content\=\"(?P<var_value>.*?)\"\>/';

	private const INCLUDE_FILE = '/{\>(?P<file_name>.*?)}/';

	private $vars = [];
	private $template_file;
	private $out_file;
	private $out_content;
	private $base_root;

	/**
	 * Parser constructor.
	 *
	 * @param array $vars
	 */
	public function __construct( array $vars ) {
		$this->vars = $vars;
	}

	/**
	 * Named constructor. Create an instance in order to parse a file.
	 *
	 * @param string $template_file
	 * @param array  $vars
	 *
	 * @return $this
	 */
	public static function from_file( string $template_file, array $vars ): self {
		$parser = new self( $vars );

		$parser->out_file      = $template_file . '.html';
		$parser->template_file = $template_file . '.tpl';

		return $parser;
	}

	/**
	 *  Parse template and return final content.
	 *
	 * @return string
	 */
	public function parse_template(): string {
		if ( empty( $this->template_file ) ) {
			return '';
		}

		$template_content = file_get_contents( $this->vars[ 'document_root' ] . $this->template_file ) ?: '';
		$out_content      = $this->parse( $template_content );

		//Optimize output
		$out_content = preg_replace( '/\s+/', ' ', $out_content );

		return $this->out_content = trim( $out_content );
	}

	/**
	 * Save output.
	 *
	 * @return void
	 */
	public function save(): void {
		file_put_contents( $this->vars[ 'document_root' ] . $this->out_file, $this->out_content );
	}

	/**
	 * Parse a text using as vars $vars
	 *
	 * @param string $content
	 *
	 * @return string
	 */
	final public function parse( string $content = '' ): string {
		$patterns_and_callbacks = [
			self::VAR_ASSIGN   => [ $this, 'var_assign' ],
			self::FIND_BLOCKS  => [ $this, 'do_block' ],
			self::FIND_VAR     => [ $this, 'return_var_value_from_tokens' ],
			self::INCLUDE_FILE => [ $this, 'include_file' ],
		];

		return \preg_replace_callback_array( $patterns_and_callbacks, $content );
	}

	/**
	 * Check if a content has a var inside or not.
	 *
	 * @param string $maybe_inside_var
	 *
	 * @return bool
	 */
	final public function has_var( string $maybe_inside_var ): bool {
		$matches = [];

		\preg_match( '%' . static::FIND_VAR . '%us', $maybe_inside_var, $matches );

		return !empty( $matches[ 'var' ] );
	}

	/**
	 * Replace a {{var}} for its value
	 *
	 * @param array $tokens Tokens from parsing.
	 *
	 * @return string
	 */
	final protected function return_var_value_from_tokens( array $tokens ): string {
		$var     = strtok( $tokens[ 'var' ], '|' ) ?: '';
		$filters = explode( '|', strtok( '' ) ?: '' );
		$filters = array_map( 'trim', array_filter( $filters ) );

		$value = $this->get_value_of_var_name( $var );

		foreach ( $filters as $filter_name ) {
			$filter = $this->vars[ 'filters' ][ $filter_name ] ?? '';

			if ( empty( $filter ) ) {
				continue;
			}

			$value = $filter( $value, $this->vars );
		}

		return (string) $value;
	}

	/**
	 * Parse a block statement
	 *
	 * @param array{block_name: string, var: string, content: string}  $tokens
	 *
	 * @return string
	 */
	final private function do_block( $tokens ): string {
		$block_name = $tokens[ 'block_name' ];
		$var        = $tokens[ 'var' ] ?? '';
		$content    = $tokens[ 'content' ] ?? '';

		if ( 'foreach' === $block_name ) {
			return $this->do_foreach( $var, $content );
		}

		if ( 'if' === $block_name ) {
			return $this->do_if( $var, $content );
		}

		if ( function_exists( $this->vars[ 'block' ][ $block_name ] ) ) {
			return $this->vars[ 'block' ][ $block_name ]( $content, $this->vars );
		}

		return $content;
	}

	/**
	 * Parse a foreach statement
	 *
	 * @param string $var
	 * @param string $content_of_foreach
	 *
	 * @return string
	 */
	final private function do_foreach( string $var, string $content_of_foreach ): string {
		$vars      = explode( ' as ', $var ?? '' );
		$var_name  = trim( $vars[ 0 ] );
		$var_alias = trim( $vars[ 1 ] ?? 'item' );

		$items_to_iterate = $this->get_value_of_var_name( $var_name );

		if ( empty( $items_to_iterate ) || !\is_iterable( $items_to_iterate ) || '' === $content_of_foreach ) {
			return '';
		}

		return $this->parse_content_for_all_items( $items_to_iterate, $content_of_foreach, $var_alias );
	}

	/**
	 * Retrieve value of a var
	 *
	 * @param string $var_name
	 *
	 * @return mixed|string
	 */
	final private function get_value_of_var_name( string $var_name ) {
		$var_name = \trim( $var_name );

		$vars_name = \explode( '.', $var_name );
		$value     = '';

		$vars =& $this->vars;
		foreach ( $vars_name as $var_name ) {
			if ( is_array( $vars ) ) {
				$value = $vars[ $var_name ] ?? '';
			} elseif ( \is_object( $vars ) ) {
				$value = $vars->$var_name ?? '';
			} else {
				return '';
			}

			$vars =& $value;
		}

		return $value;
	}

	/**
	 * Parse foreach content iteratively.
	 *
	 * @param iterable $items_to_iterate Items to iterate.
	 * @param string   $content_of_foreach
	 * @param string   $var_alias        Alias of each item
	 *
	 * @return string
	 */
	final private function parse_content_for_all_items( iterable $items_to_iterate, string $content_of_foreach, string $var_alias ): string {
		$index = 1;
		$max   = count( $items_to_iterate );

		$foreach_result = '';
		foreach ( $items_to_iterate as $item ) {
			$vars = $this->vars;

			$vars[ $var_alias ] = $item;
			$vars[ 'index' ]    = $index;
			$vars[ 'count' ]    = $max;
			$vars[ 'is_first' ] = 1 === $index;
			$vars[ 'is_last' ]  = $max === $index;

			$foreach_result .= ( new $this )->parse( $content_of_foreach, $vars );
			$index ++;
		}

		return $foreach_result;
	}

	/**
	 * Parse an if statement
	 *
	 * @param string $var_of_conditional
	 * @param string $content_of_conditional
	 *
	 * @return string
	 */
	final private function do_if( string $var_of_conditional, string $content_of_conditional ): string {
		$true_with_empty    = '!' === $var_of_conditional[ 0 ];
		$var_of_conditional = ltrim( $var_of_conditional, '! ' );

		if ( '' === $var_of_conditional || '' === $content_of_conditional ) {
			return '';
		}

		$condictional_is_false = empty( $this->get_value_of_var_name( $var_of_conditional ) );

		if ( $condictional_is_false !== $true_with_empty ) {
			return '';
		}

		return ( new $this )->parse( $content_of_conditional, $this->vars );
	}

	/**
	 * Assign a value to a variable.
	 *
	 * @param array{string: string} $tokens Tokens from parsing.
	 *
	 * @return string
	 */
	final private function var_assign( array $tokens ): string {
		$var   = $tokens[ 'var_name' ] ?? '';
		$value = $tokens[ 'var_value' ] ?? '';

		if ( empty( $var ) ) {
			return '';
		}

		$this->vars[ $var ] = $value;

		return '';
	}

	/**
	 * Run a {$ hook].
	 *
	 * @param array{string: string} $tokens Tokens from parsing.
	 *
	 * @return string
	 */
	final private function include_file( array $tokens ): string {
		$file_name = trim( $tokens[ 'file_name' ] ?? '' );

		return Parser::from_file( '/' . $file_name, $this->vars )->parse_template();
	}
}
