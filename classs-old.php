<?php
/**
 * Add a basic ui for adding and editing term meta. More interestingly, add two new actions to consolidate several
 * obscure and difficult to use actions.
 *
 * These new actions are:
 *     term_meta_output_form_fields
 *     term_meta_save_form_fields
 *
 * Note: We are responding to actions that are already nonced. I don't think we need additional.
 */
if ( ! class_exists( 'Term_Meta_UI' ) ) {

	class Term_Meta_UI {
		/**
		 * @var bool|Term_Meta_UI
		 */
		protected static $_instance = false;

		/**
		 * Gets the singleton instance of this class - should only get constructed once.
		 *
		 * @return bool|Term_Meta_UI
		 */
		public static function instance() {
			if ( ! self::$_instance ) {
				self::$_instance = new self();
			}

			return self::$_instance;
		}

		/**
		 * @var int The post associated with the current term we are editing.
		 */
		protected $_term_meta_post_id = null;

		/**
		 * Constructor -  Wire up actions and filters
		 */
		protected function __construct() {
			add_action( 'init', array( $this, 'action__init' ), 11 );
			add_action( 'current_screen', array( $this, 'action__current_screen' ) );
			add_action( 'term_meta_output_form_fields', array( $this, 'action__output_form_fields' ), 10, 3 );
//			add_action( 'term_meta_save_form_fields', array( $this, 'action__save_form_fields' ), 10, 3 );
			add_action( 'wp_ajax_add-meta', array( $this, 'action__wp_ajax_add_meta' ), 0 );
		}

		public function action__init () {
			$taxonomies = get_taxonomies();
			foreach ( $taxonomies as $taxonomy ) {
				add_action( "{$taxonomy}_add_form_fields",  array( $this, 'action__add_form_fields' ) );
				add_action( "{$taxonomy}_edit_form_fields", array( $this, 'action__edit_form_fields' ), 10, 2 );
				add_action( "{$taxonomy}_edit_form", array( $this, 'action__edit_form' ), 10, 2 );
				Term_Meta::instance()->register_term_meta_taxonomy( $taxonomy );
			}
			add_action( "created_term", array( $this, 'action__created_edited_term' ), 10, 3 );
			add_action( "edited_term", array( $this, 'action__created_edited_term' ), 10, 3 );
		}

		public function action__current_screen ( $screen ) {
			if ( 'edit-tags' !== $screen->base ) {
				return;
			}

			if ( isset( $_GET['tag_ID'] ) ) {
				$term = get_term( $_GET['tag_ID'], $screen->taxonomy );
				$this->_term_meta_post_id   = Term_Meta::instance()->get_term_meta_post_id( $term->taxonomy, $term );
				$term_meta_post = get_post( $this->_term_meta_post_id );
			} else {
				$term_meta_post = null;
			}

			do_action( 'add_meta_boxes_' . $screen->id, $term_meta_post );
			do_action( 'add_meta_boxes', $screen->id, $term_meta_post );

			require_once( ABSPATH . 'wp-admin/includes/meta-boxes.php' );
			add_meta_box( 'postcustom', __('Custom Fields'), 'post_custom_meta_box', $screen->id, 'meta-box' );
			add_action( 'admin_footer', array( $this, 'action__admin_footer' ) );

			wp_enqueue_script( 'postbox' );
			wp_enqueue_script( 'post' );
		}

		/*
		 * Respond to all actions of the form {$taxonomy}_add_form_fields or {$taxonomy}_edit_form_fields and turn them
		 * into a single action that better fits our use-case.
		 *
		 * This also makes it easy to remove the default ui using this:
		 *   remove_action( 'term_meta_output_form_fields', array( Term_Meta_UI::instance(), 'action__output_form_fields' ) );
		 *
		 */
		public function action__add_form_fields ( $taxonomy ) {
			do_action( 'term_meta_output_form_fields', $taxonomy, null, 'add' );
		}
		public function action__edit_form_fields ( $term, $taxonomy ) {
			do_action( 'term_meta_output_form_fields', $taxonomy, $term, 'edit' );
		}
		// Slight edge case. We need to put meta boxes outside the table when for anything else we want to be inside.
		public function action__edit_form ( $term, $taxonomy ) {
			do_action( 'term_meta_output_form_fields', $taxonomy, $term, 'meta-box' );
		}

		public function action__output_form_fields ( $taxonomy, $term, $context ) {
			if ( 'edit' == $context ) {
				return;
			} elseif ( 'meta-box' == $context ) {
				$term_meta_post = get_post( $this->_term_meta_post_id );
			} else {
				$term_meta_post = null;
			}

			/* Used to save closed meta boxes and their order */
			wp_nonce_field( 'meta-box-order', 'meta-box-order-nonce', null );
			wp_nonce_field( 'closedpostboxes', 'closedpostboxesnonce', null );
			?>
			<input type='hidden' id='post_ID' name='post_ID' value='<?php echo $this->_term_meta_post_id; ?>' />
			<div id="poststuff" style="min-width: 0">

				<div id="post-body" class="metabox-holder columns-1">

					<?php do_meta_boxes( '', $context, $term_meta_post ); ?>

				</div> <!-- #post-body -->

			</div> <!-- #poststuff -->
		<?php
		}

		public function action__admin_footer () {
			?>
			<script>jQuery(document).ready(function(){ postboxes.add_postbox_toggles(pagenow); });</script>
		<?php
		}

		/*
		 * Respond to all actions of the form create_{$taxonomy} or edited_{$taxonomy} and turn them into a single
		 * action that better fits our use-case.
		 */
		public function action__created_edited_term ( $term_id, $tt_id, $taxonomy ) {
			$term = get_term( $term_id, $taxonomy );
			$context = ( 'create_term' == current_filter() ) ? 'add' : 'edit';

			do_action( 'term_meta_save_form_fields', $taxonomy, $term, $context );
		}

		public function action__wp_ajax_add_meta ( $taxonomy, $term, $context ) {
			// $_POST['post_id'] = '489';
			// todo figure out how to get post id into ajax msg sent by postcustom
		}

		public function action__save_form_fields ( $taxonomy, $term, $context ) {
			$term_meta_post_id   = Term_Meta::instance()->get_term_meta_post_id( $term->taxonomy, $term );

			// copy the relevant bits from edit_post()
			$post_data = &$_POST;

			// Meta Stuff
			if ( isset($post_data['meta']) && $post_data['meta'] ) {
				foreach ( $post_data['meta'] as $key => $value ) {
					if ( !$meta = get_post_meta_by_id( $key ) )
						continue;
					if ( $meta->post_id != $post_ID )
						continue;
					if ( is_protected_meta( $value['key'], 'post' ) || ! current_user_can( 'edit_post_meta', $post_ID, $value['key'] ) )
						continue;
					update_meta( $key, $value['key'], $value['value'] );
				}
			}

			if ( isset($post_data['deletemeta']) && $post_data['deletemeta'] ) {
				foreach ( $post_data['deletemeta'] as $key => $value ) {
					if ( !$meta = get_post_meta_by_id( $key ) )
						continue;
					if ( $meta->post_id != $post_ID )
						continue;
					if ( is_protected_meta( $meta->meta_key, 'post' ) || ! current_user_can( 'delete_post_meta', $post_ID, $meta->meta_key ) )
						continue;
					delete_meta( $key );
				}
			}

			add_meta( $post_ID );

			update_post_meta( $post_ID, '_edit_last', get_current_user_id() );
		}
	}

	Term_Meta_UI::instance();
}

