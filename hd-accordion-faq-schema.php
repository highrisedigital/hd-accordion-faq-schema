<?php
/**
 * Plugin Name:       HD Accordion FAQ Schema
 * Description:       Adds an "FAQ" style to the core Accordion block. FAQ accordions are added to the page's schema as an FAQPage, through Yoast SEO, Rank Math, All in One SEO or The SEO Framework when one is active, or as standalone JSON-LD otherwise. Full blocks (paragraphs, lists, links) are allowed in answers.
 * Version:           1.2.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            Highrise Digital
 * Author URI:        https://highrise.digital
 * License:           GPL-2.0-or-later
 * Text Domain:       hd-accordion-faq-schema
 * Update URI:        https://github.com/highrisedigital/hd-accordion-faq-schema
 *
 * How it works
 * ------------
 * 1. The plugin registers an "FAQ" block style for the core Accordion block,
 *    so editors can mark any accordion as an FAQ from the Styles panel.
 * 2. When a page is shown, the plugin looks through the page's blocks for
 *    accordions with that style.
 * 3. Each accordion item becomes a schema "Question" with an "Answer".
 * 4. The plugin works out which SEO plugin is active and adds the questions
 *    to that plugin's own schema graph, marking its WebPage piece as an
 *    "FAQPage" too. This mirrors what Yoast's own FAQ block outputs, but
 *    answers can contain any blocks. Supported graphs:
 *    - Yoast SEO (wpseo_schema_graph)
 *    - Rank Math (rank_math/json_ld)
 *    - All in One SEO (aioseo_schema_output)
 *    - The SEO Framework 5.0+ (the_seo_framework_schema_graph_data)
 * 5. SEOPress has no single schema graph to join, so with SEOPress, or with
 *    no SEO plugin at all, the FAQPage is printed as its own JSON-LD script.
 *    The same happens if an SEO plugin is active but its schema output has
 *    been turned off, so the FAQ schema is never lost.
 *
 * Updates
 * -------
 * New versions are published as releases of the plugin's GitHub repository
 * (see HD_FAQ_GITHUB_REPO below), and show in the WordPress dashboard like
 * any other plugin update.
 *
 * @package HD_Accordion_FAQ_Schema
 */

// Stop the file being loaded directly, outside of WordPress.
defined( 'ABSPATH' ) || exit;

/**
 * Register the "FAQ" block style for the core Accordion block.
 *
 * Block styles appear in the block's Styles panel in the editor. Choosing
 * one adds the class "is-style-{name}" to the block, which is how this
 * plugin later tells an FAQ accordion apart from an ordinary one.
 *
 * The style carries no CSS of its own: how accordions look is left to the
 * theme.
 */
function hd_faq_register_block_style() {
	register_block_style(
		'core/accordion',
		[
			// Saved on the block as the class "is-style-faq".
			'name'  => 'faq',
			// The label shown to editors in the Styles panel.
			'label' => __( 'FAQ', 'hd-accordion-faq-schema' ),
		]
	);
}
add_action( 'init', 'hd_faq_register_block_style' );

/**
 * Collect question/answer pairs from FAQ-style accordions in a list of blocks.
 *
 * Blocks can be nested (an accordion inside a group inside columns, for
 * example), so this function calls itself for any block that has inner
 * blocks. It also follows synced patterns, whose content is stored in a
 * separate post and only referenced from the page by ID.
 *
 * @param array $blocks Parsed blocks, as returned by parse_blocks().
 * @param int   $depth  How deep we are in synced patterns, to stop loops.
 * @return array[] List of [ 'question' => string, 'answer' => string ].
 */
function hd_faq_find_faqs( $blocks, $depth = 0 ) {
	$faqs = [];

	foreach ( $blocks as $block ) {

		/*
		 * Synced pattern: the block only holds a reference (the ID of a
		 * wp_block post), so load that post and search its blocks instead.
		 * The depth limit stops a pattern that includes itself (directly or
		 * via another pattern) from looping forever.
		 */
		if ( 'core/block' === $block['blockName'] && ! empty( $block['attrs']['ref'] ) && $depth < 3 ) {
			$synced = get_post( $block['attrs']['ref'] );
			if ( $synced ) {
				$faqs = array_merge( $faqs, hd_faq_find_faqs( parse_blocks( $synced->post_content ), $depth + 1 ) );
			}
			continue;
		}

		// A block's classes are saved as one space-separated string.
		$classes = explode( ' ', $block['attrs']['className'] ?? '' );

		/*
		 * FAQ accordion: each inner block is an accordion item holding one
		 * question and answer. Items with an empty question or answer come
		 * back as null and are skipped.
		 */
		if ( 'core/accordion' === $block['blockName'] && in_array( 'is-style-faq', $classes, true ) ) {
			foreach ( $block['innerBlocks'] as $item ) {
				$faq = hd_faq_get_faq_from_item( $item );
				if ( $faq ) {
					$faqs[] = $faq;
				}
			}
			continue;
		}

		// Any other block: search inside it, in case an FAQ is nested there.
		if ( ! empty( $block['innerBlocks'] ) ) {
			$faqs = array_merge( $faqs, hd_faq_find_faqs( $block['innerBlocks'], $depth ) );
		}
	}

	return $faqs;
}

/**
 * Turn one Accordion item into a question and answer.
 *
 * An accordion item contains two blocks:
 * - core/accordion-heading: the clickable question.
 * - core/accordion-panel: the answer, which can hold any blocks.
 *
 * @param array $item A parsed core/accordion-item block.
 * @return array|null The question and answer, or null if either is empty.
 */
function hd_faq_get_faq_from_item( $item ) {
	$question = '';
	$answer   = '';

	foreach ( $item['innerBlocks'] as $inner ) {
		if ( 'core/accordion-heading' === $inner['blockName'] ) {
			/*
			 * The heading's saved HTML is a button containing two spans: the
			 * question text and the "+" icon. Take only the text span, then
			 * remove any formatting tags (bold, links) from the question.
			 */
			if ( preg_match( '/<span class="wp-block-accordion-heading__toggle-title">(.*?)<\/span>/s', $inner['innerHTML'], $match ) ) {
				$question = trim( wp_strip_all_tags( $match[1] ) );
			}
		}

		if ( 'core/accordion-panel' === $inner['blockName'] ) {
			// Render each block in the panel to HTML and join them together,
			// so answers can contain several paragraphs, lists and so on.
			foreach ( $inner['innerBlocks'] as $panel_block ) {
				$answer .= render_block( $panel_block );
			}
		}
	}

	/*
	 * Google only reads a few HTML tags in FAQ answers (see its FAQPage
	 * structured data guidelines), so strip everything else. Attributes are
	 * removed too, apart from href on links.
	 */
	$allowed = [
		'p'      => [],
		'br'     => [],
		'ul'     => [],
		'ol'     => [],
		'li'     => [],
		'a'      => [ 'href' => [] ],
		'strong' => [],
		'b'      => [],
		'em'     => [],
		'i'      => [],
		'h2'     => [],
		'h3'     => [],
		'h4'     => [],
	];

	// Clean the HTML, then collapse line breaks and runs of spaces into one.
	$answer  = trim( preg_replace( '/\s+/', ' ', wp_kses( $answer, $allowed ) ) );

	// An item without both a question and an answer isn't a valid FAQ entry.
	if ( '' === $question || '' === $answer ) {
		return null;
	}

	return [
		// Turn HTML entities such as &#8217; back into real characters, since
		// the question is plain text in the schema.
		'question' => html_entity_decode( $question, ENT_QUOTES, 'UTF-8' ),
		'answer'   => $answer,
	];
}

/**
 * Get the FAQs on the page being shown, if there are any.
 *
 * Several SEO plugins may ask for the FAQs during one page load (and the
 * fallback in the footer asks again), so the result is worked out once and
 * kept for the rest of the request.
 *
 * @return array[] List of [ 'question' => string, 'answer' => string ].
 */
function hd_faq_get_page_faqs() {
	static $faqs = null;

	if ( null !== $faqs ) {
		return $faqs;
	}

	$faqs = [];

	// FAQs only make sense on a single post or page, not archives or search.
	if ( ! is_singular() ) {
		return $faqs;
	}

	$content = get_post()->post_content;

	// Quick text check first, so pages with no FAQ accordion (or synced
	// pattern that might hold one) skip the block parsing entirely.
	if ( ! str_contains( $content, 'is-style-faq' ) && ! str_contains( $content, '<!-- wp:block ' ) ) {
		return $faqs;
	}

	$faqs = hd_faq_find_faqs( parse_blocks( $content ) );

	return $faqs;
}

/**
 * Work out which SEO plugin's schema graph the FAQ should be added to.
 *
 * Each SEO plugin is spotted by a constant or function it defines when it
 * loads. If more than one is active (which is rare, and best avoided), the
 * first in this list wins, so the FAQ is only ever added once.
 *
 * SEOPress isn't listed: it doesn't build a single schema graph that other
 * plugins can join, so the standalone JSON-LD is used with it instead.
 *
 * @return string 'yoast', 'rank-math', 'aioseo', 'tsf' or 'standalone'.
 */
function hd_faq_get_integration() {
	$integration = 'standalone';

	if ( defined( 'WPSEO_VERSION' ) ) {
		$integration = 'yoast';
	} elseif ( defined( 'RANK_MATH_VERSION' ) ) {
		$integration = 'rank-math';
	} elseif ( function_exists( 'aioseo' ) ) {
		$integration = 'aioseo';
	} elseif ( defined( 'THE_SEO_FRAMEWORK_VERSION' ) && version_compare( THE_SEO_FRAMEWORK_VERSION, '5.0', '>=' ) ) {
		// The schema graph filter arrived in The SEO Framework 5.0. Older
		// versions fall through to the standalone JSON-LD.
		$integration = 'tsf';
	}

	/**
	 * Filter which integration is used, for example to force the standalone
	 * JSON-LD.
	 *
	 * @param string $integration 'yoast', 'rank-math', 'aioseo', 'tsf' or 'standalone'.
	 */
	return apply_filters( 'hd_faq_schema_integration', $integration );
}

/**
 * Hook into the schema graph of whichever SEO plugin is active.
 *
 * This runs on plugins_loaded, once every plugin has been loaded and has
 * defined the constants hd_faq_get_integration() looks for.
 */
function hd_faq_setup_integration() {
	switch ( hd_faq_get_integration() ) {
		case 'yoast':
			// 2 accepted arguments so the function receives Yoast's $context.
			add_filter( 'wpseo_schema_graph', 'hd_faq_add_schema_yoast', 10, 2 );
			break;

		case 'rank-math':
			// Late priority, so Rank Math's own WebPage piece is already there.
			add_filter( 'rank_math/json_ld', 'hd_faq_add_schema_to_graph', 99 );
			break;

		case 'aioseo':
			add_filter( 'aioseo_schema_output', 'hd_faq_add_schema_to_graph' );
			break;

		case 'tsf':
			add_filter( 'the_seo_framework_schema_graph_data', 'hd_faq_add_schema_to_graph' );
			break;

		default:
			// No graph to join, so print the FAQPage in the page head.
			add_action( 'wp_head', 'hd_faq_print_standalone_schema', 20 );
			return;
	}

	/*
	 * Safety net: if the SEO plugin never built its graph on this page (its
	 * schema output can be turned off in its settings, for example), print
	 * the FAQPage on its own in the footer instead.
	 */
	add_action( 'wp_footer', 'hd_faq_print_fallback_schema' );
}
add_action( 'plugins_loaded', 'hd_faq_setup_integration' );

/**
 * Remember, or check, whether an SEO plugin's schema graph has been built.
 *
 * @param bool $ran Pass true when a graph filter runs.
 * @return bool Whether a graph filter has run on this page.
 */
function hd_faq_graph_ran( $ran = false ) {
	static $has_run = false;

	if ( $ran ) {
		$has_run = true;
	}

	return $has_run;
}

/**
 * Get the URL the FAQ's schema @ids are based on.
 *
 * @param string $url A canonical URL from the SEO plugin, if it has one.
 * @return string The URL to use.
 */
function hd_faq_get_url( $url = '' ) {
	if ( empty( $url ) ) {
		$url = wp_get_canonical_url();
	}

	return $url ? $url : get_permalink();
}

/**
 * Build a schema Question piece for each FAQ.
 *
 * Positions count from 1, and each question gets a unique @id so a WebPage
 * piece can point to it.
 *
 * @param array[] $faqs FAQs from hd_faq_get_page_faqs().
 * @param string  $url  The page URL, used for each question's @id.
 * @return array[] The Question pieces.
 */
function hd_faq_get_question_pieces( $faqs, $url ) {
	$language  = get_bloginfo( 'language' );
	$questions = [];

	foreach ( $faqs as $position => $faq ) {
		$id          = $url . '#faq-' . ( $position + 1 );
		$questions[] = [
			'@type'          => 'Question',
			'@id'            => $id,
			'position'       => $position + 1,
			'url'            => $id,
			'name'           => $faq['question'],
			'answerCount'    => 1,
			'acceptedAnswer' => [
				'@type'      => 'Answer',
				'text'       => $faq['answer'],
				'inLanguage' => $language,
			],
			'inLanguage'     => $language,
		];
	}

	return $questions;
}

/**
 * Add the page's FAQs to an SEO plugin's schema graph.
 *
 * SEO plugins output a page's schema as one JSON-LD "graph": a list of
 * connected pieces (WebPage, Organization, BreadcrumbList and so on) printed
 * in the page head, and each plugin has a filter to change that list just
 * before it's printed. Yoast, All in One SEO and The SEO Framework pass a
 * plain list; Rank Math passes the pieces keyed by name. Both work here.
 * This function:
 * 1. Adds "FAQPage" to the type of the WebPage piece.
 * 2. Adds one Question piece per FAQ, each with its accepted Answer.
 * 3. Links the questions to the page through the WebPage's mainEntity.
 *
 * If the graph has no WebPage piece, a separate FAQPage piece holding the
 * questions is added instead.
 *
 * @param array  $graph The SEO plugin's schema pieces.
 * @param string $url   A canonical URL from the SEO plugin, if it has one.
 * @return array The graph, with the FAQ added.
 */
function hd_faq_add_schema_to_graph( $graph, $url = '' ) {
	hd_faq_graph_ran( true );

	$faqs = hd_faq_get_page_faqs();

	// Nothing found, or the graph isn't one we can add to.
	if ( empty( $faqs ) || ! is_array( $graph ) ) {
		return $graph;
	}

	$url       = hd_faq_get_url( $url );
	$questions = hd_faq_get_question_pieces( $faqs, $url );

	// References to each question, added to the WebPage's mainEntity below.
	$refs = [];
	foreach ( $questions as $question ) {
		$refs[] = [ '@id' => $question['@id'] ];
	}

	// Find the WebPage piece and remember where it is, for mainEntity below.
	foreach ( $graph as $key => $piece ) {
		$types = (array) ( is_array( $piece ) ? ( $piece['@type'] ?? [] ) : [] );

		if ( in_array( 'WebPage', $types, true ) ) {
			// The page itself becomes an FAQPage too, as Yoast's FAQ block does.
			$graph[ $key ]['@type'] = array_values( array_unique( array_merge( $types, [ 'FAQPage' ] ) ) );
			$webpage_key            = $key;
			break;
		}
	}

	foreach ( $questions as $question ) {
		$graph[] = $question;
	}

	if ( isset( $webpage_key ) ) {
		// Link the questions to the page. Merge with any existing mainEntity,
		// in case the SEO plugin's own FAQ block is also on the page.
		$existing = $graph[ $webpage_key ]['mainEntity'] ?? [];

		// A single entity (rather than a list of them) is wrapped in a list.
		if ( isset( $existing['@id'] ) || isset( $existing['@type'] ) ) {
			$existing = [ $existing ];
		}

		$graph[ $webpage_key ]['mainEntity'] = array_merge( $existing, $refs );
	} else {
		// No WebPage piece to mark, so add an FAQPage piece of our own.
		$graph[] = [
			'@type'      => 'FAQPage',
			'@id'        => $url . '#faq',
			'url'        => $url,
			'mainEntity' => $refs,
		];
	}

	return $graph;
}

/**
 * Add the page's FAQs to Yoast SEO's schema graph.
 *
 * Yoast passes a context object along with the graph, which holds its
 * canonical URL for the page. Using it matches Yoast's own FAQ block.
 *
 * @param array  $graph   Yoast's schema pieces.
 * @param object $context Yoast's Meta_Tags_Context for the current page.
 * @return array The graph, with the FAQ added.
 */
function hd_faq_add_schema_yoast( $graph, $context ) {
	return hd_faq_add_schema_to_graph( $graph, $context->canonical ?? '' );
}

/**
 * Print the page's FAQs as a standalone FAQPage JSON-LD script.
 *
 * Used when there's no SEO plugin schema graph to join. Questions are nested
 * directly inside the FAQPage, which is the shape Google's FAQPage examples
 * use.
 */
function hd_faq_print_standalone_schema() {
	$faqs = hd_faq_get_page_faqs();

	if ( empty( $faqs ) ) {
		return;
	}

	$url    = hd_faq_get_url();
	$schema = [
		'@context'   => 'https://schema.org',
		'@type'      => 'FAQPage',
		'@id'        => $url . '#faq',
		'url'        => $url,
		'inLanguage' => get_bloginfo( 'language' ),
		'mainEntity' => hd_faq_get_question_pieces( $faqs, $url ),
	];

	/*
	 * JSON_HEX_TAG writes < and > as < and >, so HTML in the
	 * answers can't close the script tag early. The JSON still reads the
	 * same.
	 */
	printf(
		"<script type=\"application/ld+json\" class=\"hd-faq-schema\">%s</script>\n",
		wp_json_encode( $schema, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
	);
}

/**
 * Print the standalone FAQ schema in the footer if the SEO plugin's graph
 * was never built on this page.
 */
function hd_faq_print_fallback_schema() {
	if ( ! hd_faq_graph_ran() ) {
		hd_faq_print_standalone_schema();
	}
}

/**
 * The GitHub repository this plugin is released from, as "owner/repo".
 *
 * Must match the "Update URI" line in the plugin header.
 */
const HD_FAQ_GITHUB_REPO = 'highrisedigital/hd-accordion-faq-schema';

/**
 * Tell WordPress about new versions published as GitHub releases.
 *
 * The "Update URI" line in the plugin header tells WordPress not to look for
 * this plugin on WordPress.org. Instead, when WordPress checks for plugin
 * updates, it runs the update_plugins_{hostname} filter for the header's
 * hostname (github.com here) and uses whatever the filter returns.
 *
 * Each release needs a zip named hd-accordion-faq-schema.zip attached to it,
 * holding the plugin in a hd-accordion-faq-schema folder. GitHub's own
 * "Source code" zips can't be used: they unpack to a folder named after the
 * repository and commit, which WordPress would install as a different plugin.
 * The release workflow in .github/workflows builds the right zip on each tag.
 *
 * @param array|false $update      The update data so far (false by default).
 * @param array       $plugin_data The plugin's header data.
 * @param string      $plugin_file The plugin's path, relative to the plugins folder.
 * @return array|false The update data, or $update if there's nothing to add.
 */
function hd_faq_check_for_update( $update, $plugin_data, $plugin_file ) {
	// The filter runs for every plugin with a github.com Update URI.
	if ( plugin_basename( __FILE__ ) !== $plugin_file ) {
		return $update;
	}

	$release = hd_faq_get_latest_release();

	if ( empty( $release ) ) {
		return $update;
	}

	// WordPress compares this version with the installed one, and only
	// offers the update when it's newer.
	return [
		'slug'    => dirname( $plugin_file ),
		'version' => $release['version'],
		'url'     => $release['url'],
		'package' => $release['package'],
	];
}
add_filter( 'update_plugins_github.com', 'hd_faq_check_for_update', 10, 3 );

/**
 * Get the latest GitHub release of the plugin.
 *
 * The answer is kept for six hours, so sites don't ask GitHub on every
 * update check (GitHub limits how often a site can ask without signing in).
 * Clicking "Check again" on the Dashboard > Updates screen skips the stored
 * answer.
 *
 * @return array Version, release page URL and zip URL, or an empty array if
 *               there's no usable release (or GitHub couldn't be reached).
 */
function hd_faq_get_latest_release() {
	$cache_key = 'hd_faq_latest_release';
	$release   = get_site_transient( $cache_key );

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only read to skip the cache.
	if ( false !== $release && ! isset( $_GET['force-check'] ) ) {
		return $release;
	}

	$release  = [];
	$response = wp_remote_get(
		'https://api.github.com/repos/' . HD_FAQ_GITHUB_REPO . '/releases/latest',
		[
			'timeout' => 10,
			'headers' => [ 'Accept' => 'application/vnd.github+json' ],
		]
	);

	if ( 200 === wp_remote_retrieve_response_code( $response ) ) {
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		// Find the plugin zip among the files attached to the release.
		foreach ( $body['assets'] ?? [] as $asset ) {
			if ( 'hd-accordion-faq-schema.zip' === $asset['name'] ) {
				$release = [
					// Tags are written as "v1.2.0", versions as "1.2.0".
					'version' => ltrim( $body['tag_name'], 'v' ),
					'url'     => $body['html_url'],
					'package' => $asset['browser_download_url'],
				];
				break;
			}
		}
	}

	// If GitHub couldn't be reached, try again in an hour rather than six.
	set_site_transient( $cache_key, $release, empty( $release ) ? HOUR_IN_SECONDS : 6 * HOUR_IN_SECONDS );

	return $release;
}
