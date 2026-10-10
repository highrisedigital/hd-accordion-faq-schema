<?php
/**
 * Plugin Name:       HD Accordion FAQ Schema
 * Description:       Adds an "FAQ" style to the core Accordion block. FAQ accordions are added to Yoast SEO's schema as an FAQPage, with full blocks (paragraphs, lists, links) allowed in answers.
 * Version:           1.0.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Requires Plugins:  wordpress-seo
 * Author:            Highrise Digital
 * Author URI:        https://highrise.digital
 * License:           GPL-2.0-or-later
 * Text Domain:       hd-accordion-faq-schema
 *
 * How it works
 * ------------
 * 1. The plugin registers an "FAQ" block style for the core Accordion block,
 *    so editors can mark any accordion as an FAQ from the Styles panel.
 * 2. When Yoast SEO builds its schema graph for a page, the plugin looks
 *    through the page's blocks for accordions with that style.
 * 3. Each accordion item becomes a schema "Question" with an "Answer", and
 *    the page's WebPage piece is also marked as an "FAQPage". This mirrors
 *    what Yoast's own FAQ block outputs, but answers can contain any blocks.
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
 * Add FAQPage schema to Yoast's graph when the page has FAQ accordions.
 *
 * Yoast outputs all of a page's schema as one JSON-LD "graph": a list of
 * connected pieces (WebPage, Organization, BreadcrumbList and so on) printed
 * in the page head. The wpseo_schema_graph filter lets us change that list
 * just before it's printed. This function:
 * 1. Adds "FAQPage" to the type of the WebPage piece.
 * 2. Adds one Question piece per FAQ, each with its accepted Answer.
 * 3. Links the questions to the page through the WebPage's mainEntity.
 *
 * @param array  $graph   Yoast's schema pieces.
 * @param object $context Yoast's Meta_Tags_Context for the current page.
 * @return array The graph, with the FAQ added.
 */
function hd_faq_add_schema( $graph, $context ) {
	// FAQs only make sense on a single post or page, not archives or search.
	if ( ! is_singular() ) {
		return $graph;
	}

	$content = get_post()->post_content;

	// Quick text check first, so pages with no FAQ accordion (or synced
	// pattern that might hold one) skip the block parsing entirely.
	if ( ! str_contains( $content, 'is-style-faq' ) && ! str_contains( $content, '<!-- wp:block ' ) ) {
		return $graph;
	}

	$faqs = hd_faq_find_faqs( parse_blocks( $content ) );

	// Nothing found (for example, a synced pattern without an FAQ in it).
	if ( empty( $faqs ) ) {
		return $graph;
	}

	// Use Yoast's canonical URL, as Yoast's own FAQ block does, falling back
	// to the permalink if it isn't set.
	$url      = ! empty( $context->canonical ) ? $context->canonical : get_permalink();
	$language = get_bloginfo( 'language' );

	// References to each question, added to the WebPage's mainEntity below.
	$refs     = [];

	// Find the WebPage piece and remember where it is, for mainEntity below.
	foreach ( $graph as $index => $piece ) {
		$types = (array) ( $piece['@type'] ?? [] );

		if ( in_array( 'WebPage', $types, true ) ) {
			// The page itself becomes an FAQPage too, as Yoast's FAQ block does.
			$graph[ $index ]['@type'] = array_values( array_unique( array_merge( $types, [ 'FAQPage' ] ) ) );
			$webpage_index            = $index;
		}
	}

	// Add a Question piece for each FAQ. Positions count from 1, and each
	// question gets a unique @id so the WebPage can point to it.
	foreach ( $faqs as $position => $faq ) {
		$id      = $url . '#faq-' . ( $position + 1 );
		$refs[]  = [ '@id' => $id ];
		$graph[] = [
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

	// Link the questions to the page. Merge with any existing mainEntity, in
	// case Yoast's own FAQ block is also on the page.
	if ( isset( $webpage_index ) ) {
		$existing = $graph[ $webpage_index ]['mainEntity'] ?? [];
		$graph[ $webpage_index ]['mainEntity'] = array_merge( $existing, $refs );
	}

	return $graph;
}
// Priority 10, and 2 accepted arguments so the function receives $context.
add_filter( 'wpseo_schema_graph', 'hd_faq_add_schema', 10, 2 );
