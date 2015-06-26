<?php
/**
 * Class to interface with term-data-store and to cache frequently needed data.
 *
 * Adds term meta to terms of select taxonomies. This is achieved by pairing a custom post type with each registered
 * taxonomy. If the taxonomy already contains terms the associated posts will be created when meta is added to the term.
 *
 * Taxonomy post-type pairs are stored in $_taxonomies. Ids are cached in $_term_post_ids to reduce database hits.
 *
 */

if ( ! class_exists( 'Term_Meta' ) ) {

class Term_Meta {
	/**
	 * @var bool|Term_Meta
	 */
	protected static $_instance = false;

	/**
	 * Gets the singleton instance of this class - should only get constructed once.
	 *
	 * @return bool|Term_Meta
	 */
	public static function instance() {
		if ( ! self::$_instance ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * @var array Taxonomies that have term meta enabled.
	 */
	protected $_taxonomies = array();

	/**
	 * @var array Stores previously retrieved ids to reduce db calls.
	 */
	protected $_term_post_ids = array();

	/**
	 * Constructor -  Wire up actions and filters
	 */
	protected function __construct() {
		add_action( '', array( $this, 'create_term_post' ) );
	}

	/**
	 *
	 * The library, term-data-store, used to create the association assumes no pre-existing terms. It then creates a new
	 * paired custom post each time a term is added. Since we may be starting with pre-existing terms, we fire an action
	 * in get_taxonomy_term_id when we detect a missing paired post.
	 *
	 * This action is then picked up by this function and the paired post is added immediately. However, other plugins
	 * and themes can replace the default action and add the post asynchronously or do other stuff as needed.
	 *
	 * @param $taxonomy
	 * @param $term
	 *
	 * @return array
	 */
	public function create_term_post ( $taxonomy, $term ) {
		$post_type = get_post_type_object( $this->_taxonomies[$taxonomy] );

		add_filter( 'tds_balancing_from_post', '__return_false' );
		$post_id = wp_insert_post( array(
			'post_type' => $post_type->slug,
			'post_title'  => $term->name,
			'post_name'   => $term->slug,
			'post_status' => 'publish',
		) );
		wp_set_object_terms( $post_id, $term->term_id, $taxonomy );
		remove_filter( 'tds_balancing_from_post', '__return_false' );

		return $post_id;
	}

	/**
	 * This implementation is designed to be forward compatible with expected changes to WordPress core and will be much
	 * more efficient then. For now, only add term meta for taxonomies that need it.
	 *
	 * If you want to do anything fancy with the CPT that will be attached to the taxonomy, you can define it ahead of
	 * and pass it into the function. Otherwise this function will create it.
	 *
	 * @param string $taxonomy   Taxonomy name
	 * @param string $post_type  Post type name
	 *
	 * @return bool
	 */
	public function register_meta_taxonomy( $taxonomy, $post_type = '' ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return false;
		}

		if ( ! post_type_exists( $post_type ) ) {
			$post_type = sanitize_key( ucfirst( $taxonomy ) ) . '_tax_meta';

			$post_type_args = array(
				'show_ui' => false,
				'rewrite' => false,
				'label'   => $taxonomy . ' taxonomy meta',
			);

			register_post_type( $post_type, $post_type_args );
		}

		Term_Data_Store\add_relationship( $post_type, $taxonomy );
		$this->_taxonomies[$taxonomy] = $post_type;

	}

	/**
	 * Get ID for a taxonomy term pair.
	 *
	 * Not all taxonomy term pairs have unique IDs in WordPress core yet. All new terms created after 4.1 will, and the
	 * rest are expected to be split in 4.2 or 4.3. For now, the same term_id is used multiple times in different
	 * taxonomies.
	 *
	 * We solve this by using the post ID of the associated cpt we are using for meta storage. This function returns
	 * that unique key.
	 *
	 * This key is intended for internal use only since taxonomy terms will have a unique ID soon, and it will be
	 * different from the key returned by this function.
	 *
	 * @param string $taxonomy    The taxonomy that the term should be found in.
	 * @param string $term        The term as a string. Will also accept integer, but that is not recommended.
	 * @return bool|null|WP_Post
	 */
	public function get_taxonomy_term_id( $taxonomy, $term = '' ) {
		if ( ! array_key_exists( $taxonomy, $this->_taxonomies ) ) {
			return false;
		}

		if ( isset( $this->_term_post_ids[$taxonomy][$term] ) ) {
			return $this->_term_post_ids[$taxonomy][$term];
		}

		if ( is_int( $term ) ) {
			$term = get_term( $term, $taxonomy );
		} elseif ( is_string( $term ) ) {
			$term = get_term_by( 'name', $term, $taxonomy );
		}

		if ( is_wp_error( $term ) || ! is_object( $term ) || empty( $term->term_id ) ) {
			return false;
		}

		if ( $cpt_post = Term_Data_Store\get_related_post( $term, $taxonomy ) ) {
			$this->_term_post_ids[$taxonomy][$term->name] = $cpt_post->ID;
			return $cpt_post->ID;
		} else {
			// if we don't have a matching post, fire an action which by default creates the post. Then try again.
			do_action( 'term_meta_missing_paired_post', $taxonomy, $term );
			if ( $cpt_post = Term_Data_Store\get_related_post( $term, $taxonomy ) ) {
				$this->_term_post_ids[$taxonomy][$term->name] = $cpt_post->ID;
				return $cpt_post->ID;
			}
		}
	}
}

Term_Meta::instance();
}