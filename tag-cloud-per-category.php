<?php
/*
Plugin Name: Tag Cloud per Category
Plugin URI: https://www.duy-pham.fr/
Author: LordPretender
Author URI: https://www.duy-pham.fr/
Version: 1.0.0
Description: This is an override of the native Tag cloud widget but filtered by the current category.
*/

//Déclaration de notre extention en tant que Widget
function register_Tag_Cloud_Per_Category_Widget() {
    register_widget( 'LP_TCPC_Tag_Cloud_Per_Category_Widget' );
}
add_action( 'widgets_init', 'register_Tag_Cloud_Per_Category_Widget' );

// Documentation : http://codex.wordpress.org/Widgets_API
class LP_TCPC_Tag_Cloud_Per_Category_Widget extends WP_Widget {

	public function __construct() {
		$widget_ops = array(
			'description' => 'A cloud of your most used tags filtered by the current category.',
			'customize_selective_refresh' => true,
		);
		parent::__construct( 'tag_cloud_per_category', 'Tag Cloud per Category', $widget_ops );
	}

	/**
	 * Outputs the content for the current Tag Cloud widget instance.
	 *
	 * @since 2.8.0
	 * @access public
	 *
	 * @param array $args     Display arguments including 'before_title', 'after_title',
	 *                        'before_widget', and 'after_widget'.
	 * @param array $instance Settings for the current Tag Cloud widget instance.
	 */
	public function widget( $args, $instance ) {
		$current_taxonomy = $this->_get_current_taxonomy($instance);
		if ( !empty($instance['title']) ) {
			$title = $instance['title'];
		} else {
			if ( 'post_tag' == $current_taxonomy ) {
				$title = __('Tags');
			} else {
				$tax = get_taxonomy($current_taxonomy);
				$title = $tax->labels->name;
			}
		}
		
		$params = array(
			'taxonomy' => $current_taxonomy,
			'echo' => false
		);

		$term = get_queried_object();
		if( $term ) {
			
			$tags = $this->get_category_tags($term->term_id);
			
			if( count($tags) > 0 ) {
				
				$tags_str = implode(",", $tags);
				
				$params = array(
					'taxonomy' => $current_taxonomy,
					'include'   => $tags_str,
					'echo' => false
				);

			}
			
		}
		
		/**
		 * Filters the taxonomy used in the Tag Cloud widget.
		 *
		 * @since 2.8.0
		 * @since 3.0.0 Added taxonomy drop-down.
		 *
		 * @see wp_tag_cloud()
		 *
		 * @param array $args Args used for the tag cloud widget.
		 */
		$tag_cloud = wp_tag_cloud( apply_filters( 'widget_tag_cloud_args', $params ) );

		if ( empty( $tag_cloud ) ) {
			return;
		}

		/** This filter is documented in wp-includes/widgets/class-wp-widget-pages.php */
		$title = apply_filters( 'widget_title', $title, $instance, $this->id_base );

		echo $args['before_widget'];
		if ( $title ) {
			echo $args['before_title'] . $title . $args['after_title'];
		}

		echo '<div class="tagcloud">';

		echo $tag_cloud;

		echo "</div>\n";
		echo $args['after_widget'];
	}
	
	private function get_category_tags($varcat) {
		global $wpdb;
		$retour = array();
		
		//https://codex.wordpress.org/Transients_API
		$transcient_id = "Tags_Cloud_for_" . $varcat;
		if ( false === ( $tags = get_transient( $transcient_id ) ) ) {
			
			// It wasn't there, so regenerate the data and save the transient
			$tags = $wpdb->get_results("
				SELECT DISTINCT
					terms2.term_id as tag_ID,
					terms2.name as tag_name,
					t2.count as posts_with_tag
				FROM
					$wpdb->posts as p1
					LEFT JOIN $wpdb->term_relationships as r1 ON p1.ID = r1.object_ID
					LEFT JOIN $wpdb->term_taxonomy as t1 ON r1.term_taxonomy_id = t1.term_taxonomy_id
					LEFT JOIN $wpdb->terms as terms1 ON t1.term_id = terms1.term_id,

					$wpdb->posts as p2
					LEFT JOIN $wpdb->term_relationships as r2 ON p2.ID = r2.object_ID
					LEFT JOIN $wpdb->term_taxonomy as t2 ON r2.term_taxonomy_id = t2.term_taxonomy_id
					LEFT JOIN $wpdb->terms as terms2 ON t2.term_id = terms2.term_id
				WHERE (
					t1.taxonomy = 'category' AND
					p1.post_status = 'publish' AND
					terms1.term_id = '$varcat' AND
					t2.taxonomy = 'post_tag' AND
					p2.post_status = 'publish' AND
					p1.ID = p2.ID
				)
			");
			
			//Cache maintenu pendant 12 heures.
			set_transient( $transcient_id, $tags, 12 * HOUR_IN_SECONDS );
			
		}

		foreach ($tags as $tag) {
			$retour[] = $tag->tag_ID;
		}
		
		return $retour;
	}

	/**
	 * Handles updating settings for the current Tag Cloud widget instance.
	 *
	 * @since 2.8.0
	 * @access public
	 *
	 * @param array $new_instance New settings for this instance as input by the user via
	 *                            WP_Widget::form().
	 * @param array $old_instance Old settings for this instance.
	 * @return array Settings to save or bool false to cancel saving.
	 */
	public function update( $new_instance, $old_instance ) {
		$instance = array();
		$instance['title'] = sanitize_text_field( $new_instance['title'] );
		$instance['taxonomy'] = stripslashes($new_instance['taxonomy']);
		return $instance;
	}

	/**
	 * Outputs the Tag Cloud widget settings form.
	 *
	 * @since 2.8.0
	 * @access public
	 *
	 * @param array $instance Current settings.
	 */
	public function form( $instance ) {
		$current_taxonomy = $this->_get_current_taxonomy($instance);
		$title_id = $this->get_field_id( 'title' );
		$instance['title'] = ! empty( $instance['title'] ) ? esc_attr( $instance['title'] ) : '';

		echo '<p><label for="' . $title_id .'">' . __( 'Title:' ) . '</label>
			<input type="text" class="widefat" id="' . $title_id .'" name="' . $this->get_field_name( 'title' ) .'" value="' . $instance['title'] .'" />
		</p>';

		$taxonomies = get_taxonomies( array( 'show_tagcloud' => true ), 'object' );
		$id = $this->get_field_id( 'taxonomy' );
		$name = $this->get_field_name( 'taxonomy' );
		$input = '<input type="hidden" id="' . $id . '" name="' . $name . '" value="%s" />';

		switch ( count( $taxonomies ) ) {

		// No tag cloud supporting taxonomies found, display error message
		case 0:
			echo '<p>' . __( 'The tag cloud will not be displayed since there are no taxonomies that support the tag cloud widget.' ) . '</p>';
			printf( $input, '' );
			break;

		// Just a single tag cloud supporting taxonomy found, no need to display options
		case 1:
			$keys = array_keys( $taxonomies );
			$taxonomy = reset( $keys );
			printf( $input, esc_attr( $taxonomy ) );
			break;

		// More than one tag cloud supporting taxonomy found, display options
		default:
			printf(
				'<p><label for="%1$s">%2$s</label>' .
				'<select class="widefat" id="%1$s" name="%3$s">',
				$id,
				__( 'Taxonomy:' ),
				$name
			);

			foreach ( $taxonomies as $taxonomy => $tax ) {
				printf(
					'<option value="%s"%s>%s</option>',
					esc_attr( $taxonomy ),
					selected( $taxonomy, $current_taxonomy, false ),
					$tax->labels->name
				);
			}

			echo '</select></p>';
		}
	}

	/**
	 * Retrieves the taxonomy for the current Tag cloud widget instance.
	 *
	 * @since 4.4.0
	 * @access public
	 *
	 * @param array $instance Current settings.
	 * @return string Name of the current taxonomy if set, otherwise 'post_tag'.
	 */
	public function _get_current_taxonomy($instance) {
		if ( !empty($instance['taxonomy']) && taxonomy_exists($instance['taxonomy']) )
			return $instance['taxonomy'];

		return 'post_tag';
	}
}
